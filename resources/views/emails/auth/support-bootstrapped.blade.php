<x-mail::message>
# Benvenuto nel Supporto FatturaFacile

L'account operativo di supporto tecnico per la piattaforma **FatturaFacile** è stato inizializzato.

Di seguito sono riportate le credenziali generate per il primo accesso:

**Email:** {{ $user->email }}  
**Password Temporanea:** `{{ $rawPassword }}`

<x-mail::button :url="config('app.url')">
Accedi al Portale
</x-mail::button>

> **Nota di sicurezza:** Conservare con cura queste credenziali e procedere alla modifica della password dopo il primo accesso.

Cordiali saluti,<br>
Il Team di {{ config('app.name') }}
</x-mail::message>
