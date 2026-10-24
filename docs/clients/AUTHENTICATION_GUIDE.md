# Client authentication guide

Every request to the API except `POST /api/v1/auth/login` (and the incoming webhooks) needs two headers:

| Header | Value |
|--------|-------|
| `Authorization` | `Bearer <token>`, the token returned by `POST /api/v1/auth/login` (`data.token`) |
| `X-Tenant-ID` | UUID of the organization the user works on (`data.active_tenant.id`, or one of `data.available_tenants`) |

A user can belong to several organizations: switch with `POST /api/v1/auth/switch-tenant` or simply send another
`X-Tenant-ID`. Writes to an organization that is being restored from a backup are answered with `503` and a
`Retry-After` header: retry later.

Login response (abridged):

```json
{
  "success": true,
  "data": {
    "token": "1|abc...",
    "token_type": "Bearer",
    "user": { "id": "...", "name": "...", "email": "...", "locale": "en" },
    "active_tenant": { "id": "...", "name": "...", "default_currency": "EUR", "role": "admin" },
    "available_tenants": [ { "id": "...", "name": "...", "role": "admin" } ]
  }
}
```

## Angular 17+ (functional interceptor)

```ts
// auth.interceptor.ts
import { HttpErrorResponse, HttpHandlerFn, HttpInterceptorFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import { BehaviorSubject, catchError, filter, switchMap, take, throwError } from 'rxjs';
import { AuthService } from './auth.service';

const PUBLIC_PATHS = ['/auth/login', '/documentation'];

let refreshing = false;
const refreshed$ = new BehaviorSubject<string | null>(null);

function withAuth(request: HttpRequest<unknown>, token: string | null, tenantId: string | null): HttpRequest<unknown> {
  let headers = request.headers;
  if (token) headers = headers.set('Authorization', `Bearer ${token}`);
  if (tenantId) headers = headers.set('X-Tenant-ID', tenantId);
  return request.clone({ headers });
}

export const authInterceptor: HttpInterceptorFn = (request, next: HttpHandlerFn) => {
  const auth = inject(AuthService);

  if (PUBLIC_PATHS.some((path) => request.url.includes(path))) {
    return next(request);
  }

  return next(withAuth(request, auth.token(), auth.tenantId())).pipe(
    catchError((error: unknown) => {
      if (!(error instanceof HttpErrorResponse) || error.status !== 401 || request.url.includes('/auth/refresh')) {
        return throwError(() => error);
      }

      // Refresh once, let the other failing requests wait for the new token, then replay the request.
      if (!refreshing) {
        refreshing = true;
        refreshed$.next(null);

        return auth.refresh().pipe(
          switchMap((token) => {
            refreshing = false;
            refreshed$.next(token);
            return next(withAuth(request, token, auth.tenantId()));
          }),
          catchError((refreshError) => {
            refreshing = false;
            auth.logout(); // the session is over: back to the login page
            return throwError(() => refreshError);
          }),
        );
      }

      return refreshed$.pipe(
        filter((token): token is string => token !== null),
        take(1),
        switchMap((token) => next(withAuth(request, token, auth.tenantId()))),
      );
    }),
  );
};
```

```ts
// auth.service.ts (sketch)
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  readonly token = signal<string | null>(localStorage.getItem('erp.token'));
  readonly tenantId = signal<string | null>(localStorage.getItem('erp.tenant'));

  login(email: string, password: string) {
    return this.http.post<{ data: LoginData }>('/api/v1/auth/login', { email, password }).pipe(
      tap(({ data }) => this.store(data.token, data.active_tenant?.id ?? null)),
    );
  }

  refresh() {
    return this.http.post<{ data: { token: string } }>('/api/v1/auth/refresh', {}, {
      headers: { Authorization: `Bearer ${this.token()}`, 'X-Tenant-ID': this.tenantId() ?? '' },
    }).pipe(map(({ data }) => { this.store(data.token, this.tenantId()); return data.token; }));
  }

  logout() { this.store(null, null); /* navigate to the login page */ }
  private store(token: string | null, tenant: string | null) { /* signals + storage */ }
}
```

```ts
// app.config.ts
export const appConfig: ApplicationConfig = {
  providers: [
    provideHttpClient(withInterceptors([authInterceptor])),
    provideApi('https://erp.example.com/api'), // from the generated @erp/client package
  ],
};
```

Store the token in memory or in a short-lived session where possible; `localStorage` is shown for brevity and is
readable by any script of the page.

## Flutter (Dio interceptor)

```dart
// auth_interceptor.dart
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class AuthInterceptor extends QueuedInterceptor {
  AuthInterceptor(this._dio, this._storage);

  final Dio _dio; // the same Dio used by the generated client
  final FlutterSecureStorage _storage;

  static const _publicPaths = ['/v1/auth/login', '/documentation'];

  @override
  Future<void> onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    if (!_publicPaths.any(options.path.contains)) {
      final token = await _storage.read(key: 'erp_token');
      final tenantId = await _storage.read(key: 'erp_tenant_id');
      if (token != null) options.headers['Authorization'] = 'Bearer $token';
      if (tenantId != null) options.headers['X-Tenant-ID'] = tenantId;
    }
    handler.next(options);
  }

  @override
  Future<void> onError(DioException error, ErrorInterceptorHandler handler) async {
    final request = error.requestOptions;
    final unauthorized = error.response?.statusCode == 401;

    if (!unauthorized || request.path.contains('/v1/auth/refresh') || request.extra['retried'] == true) {
      return handler.next(error);
    }

    try {
      // QueuedInterceptor serialises the errors: concurrent 401s refresh the token only once.
      final token = await _storage.read(key: 'erp_token');
      final tenantId = await _storage.read(key: 'erp_tenant_id');
      final refreshed = await Dio(BaseOptions(baseUrl: _dio.options.baseUrl)).post<Map<String, dynamic>>(
        '/v1/auth/refresh',
        options: Options(headers: {'Authorization': 'Bearer $token', 'X-Tenant-ID': tenantId}),
      );
      final newToken = refreshed.data!['data']['token'] as String;
      await _storage.write(key: 'erp_token', value: newToken);

      request.extra['retried'] = true;
      request.headers['Authorization'] = 'Bearer $newToken';
      handler.resolve(await _dio.fetch<dynamic>(request));
    } on DioException {
      await _storage.deleteAll(); // the session is over: back to the login screen
      handler.next(error);
    }
  }
}
```

```dart
// main.dart (excerpt)
final dio = Dio(BaseOptions(baseUrl: 'https://erp.example.com/api'));
dio.interceptors.add(AuthInterceptor(dio, const FlutterSecureStorage()));
final api = ErpClient(dio: dio); // generated by openapi-generator (dart-dio)
```

Store the token only with `FlutterSecureStorage` (Keychain on iOS, Keystore-backed storage on Android), never in
shared preferences or in logs.

## Postman

Import `docs/api/postman_collection.json`, set the `baseUrl` collection variable and run **Auth → login**: its
post-response script stores `authToken` and `tenantId` in the collection session, and every other request uses them
(`Authorization: Bearer {{authToken}}`, `X-Tenant-ID: {{tenantId}}`). `refresh` and `switch-tenant` update the same
variables; `logout` clears the token.