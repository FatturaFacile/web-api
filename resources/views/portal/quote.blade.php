<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preventivo {{ $quote->quote_number }} - Portale Clienti</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: #f3f4f6; color: #1f2937; line-height: 1.5; padding: 12px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); overflow: hidden; }
        .header { background: #1e3a8a; color: #ffffff; padding: 20px; text-align: center; }
        .header h1 { font-size: 1.25rem; font-weight: 700; margin-bottom: 4px; }
        .header p { font-size: 0.875rem; opacity: 0.9; }
        .content { padding: 20px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-accepted { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .meta-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin: 15px 0; }
        .meta-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 0.875rem; }
        .meta-label { color: #6b7280; }
        .meta-val { font-weight: 600; }
        .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 0.875rem; }
        .items-table th { background: #f3f4f6; text-align: left; padding: 10px; border-bottom: 2px solid #e5e7eb; color: #4b5563; }
        .items-table td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
        .total-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin: 20px 0; text-align: right; }
        .total-amount { font-size: 1.5rem; font-weight: 800; color: #1e40af; }
        .btn-stack { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }
        .btn { display: block; width: 100%; padding: 16px; text-align: center; border-radius: 8px; font-size: 1rem; font-weight: 700; text-decoration: none; cursor: pointer; border: none; }
        .btn-approve { background: #10b981; color: #ffffff; }
        .btn-approve:hover { background: #059669; }
        .btn-reject { background: #ef4444; color: #ffffff; }
        .btn-reject:hover { background: #dc2626; }
        .btn-pdf { background: #ffffff; color: #374151; border: 1px solid #d1d5db; }
        .footer { text-align: center; padding: 16px; font-size: 0.75rem; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $quote->contact->company_name ?? 'Fattura Facile' }}</h1>
            <p>Preventivo {{ $quote->quote_number }}</p>
        </div>

        <div class="content">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.875rem; color: #6b7280;">Data: {{ $quote->date?->format('d/m/Y') }}</span>
                @if($quote->status === 'accepted')
                    <span class="badge badge-accepted">Accettato</span>
                @elseif($quote->status === 'rejected')
                    <span class="badge badge-rejected">Rifiutato</span>
                @else
                    <span class="badge badge-pending">In Attesa</span>
                @endif
            </div>

            <div class="meta-card">
                <div class="meta-row">
                    <span class="meta-label">Cliente:</span>
                    <span class="meta-val">{{ $quote->contact->company_name ?: trim(($quote->contact->first_name ?? '').' '.($quote->contact->last_name ?? '')) }}</span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Scadenza Validità:</span>
                    <span class="meta-val">{{ $quote->valid_until ? $quote->valid_until->format('d/m/Y') : 'Non specificata' }}</span>
                </div>
            </div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th>Descrizione</th>
                        <th style="text-align: right;">Qtà</th>
                        <th style="text-align: right;">Totale</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($quote->items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td style="text-align: right;">{{ (float) $item->quantity }}</td>
                            <td style="text-align: right;">{{ number_format((float) $item->line_total, 2) }} {{ $quote->currency }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="total-box">
                <div style="font-size: 0.875rem; color: #4b5563;">Totale Documento (IVA incl.)</div>
                <div class="total-amount">{{ number_format((float) $quote->total_gross, 2) }} {{ $quote->currency }}</div>
            </div>

            @if($quote->status !== 'accepted' && $quote->status !== 'rejected')
                <div class="btn-stack">
                    <form method="POST" action="/portal/quotes/{{ $token }}/approve">
                        @csrf
                        <input type="hidden" name="signer_name" value="{{ $quote->contact->company_name ?: 'Cliente' }}">
                        <button type="submit" class="btn btn-approve">✓ Accetta Preventivo</button>
                    </form>
                    <form method="POST" action="/portal/quotes/{{ $token }}/reject">
                        @csrf
                        <button type="submit" class="btn btn-reject">✕ Rifiuta</button>
                    </form>
                    <a href="/portal/quotes/{{ $token }}/pdf" class="btn btn-pdf">Scarica PDF</a>
                </div>
            @else
                <div class="btn-stack">
                    <a href="/portal/quotes/{{ $token }}/pdf" class="btn btn-pdf">Scarica Copia PDF</a>
                </div>
            @endif
        </div>

        <div class="footer">
            Accesso protetto tramite link cifrato crittografico • Fattura Facile
        </div>
    </div>
</body>
</html>
