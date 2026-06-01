{{--
    Receipt template for the desktop API (task 5.4).

    This is the *one* Blade template the desktop SPA renders. Every
    other user-facing screen is Vue 3. The template is intentionally
    minimal and self-contained — no AdminLTE, no script dependencies,
    no helper-function calls — because:

      1) The template is rendered server-side and embedded as a string
         inside a JSON envelope by `PosApiController::receipt()`.
      2) The Electron print pipeline (Phase 10) will load this HTML
         into a hidden BrowserWindow and call `webContents.print()` /
         `webContents.printToPDF()`. Pulling in AdminLTE assets would
         only bloat the rendered output without affecting the print
         result.
      3) The legacy receipt templates (classic / elegant / slim) live
         alongside this file under `resources/views/sale_pos/receipts/`.
         Phase 10 will introduce a layout selector that picks one of
         them based on `business_locations.invoice_layout_id`. Until
         then this template is the single source of truth for receipt
         HTML returned by `/api/v1/pos/sales/{id}/receipt`.

    Validates: R13.2, R13.3, R13.6.

    Variables in scope:
        $receipt — associative array prepared by
                   `PosApiController::receipt()`. See that method's
                   docblock for the exact shape.
--}}<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt #{{ $receipt['invoice_no'] }}</title>
    <style>
        body { font-family: monospace; font-size: 12px; max-width: 320px; margin: 0 auto; padding: 8px; }
        .header, .footer { text-align: center; }
        .header h3 { margin: 0 0 4px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2px 4px; text-align: left; border-bottom: 1px dashed #ccc; }
        .totals tr td { border: none; }
        .right { text-align: right; }
        hr { border: none; border-top: 1px dashed #999; margin: 6px 0; }
        small { color: #555; }
    </style>
</head>
<body>
    <div class="header">
        <h3>{{ $receipt['business']['name'] }}</h3>
        <p>
            {{ $receipt['location']['name'] }}@if (! empty($receipt['location']['address']))<br>{!! $receipt['location']['address'] !!}@endif
            @if (! empty($receipt['location']['mobile']))<br>Tel: {{ $receipt['location']['mobile'] }}@endif
            @if (! empty($receipt['location']['email']))<br>{{ $receipt['location']['email'] }}@endif
        </p>
    </div>
    <hr>
    <p>
        <strong>Invoice:</strong> {{ $receipt['invoice_no'] }}<br>
        <strong>Date:</strong> {{ $receipt['transaction_date'] }}<br>
        <strong>Customer:</strong> {{ $receipt['customer']['name'] ?? 'Walk-in' }}
        @if (! empty($receipt['customer']['mobile']))<br><strong>Mobile:</strong> {{ $receipt['customer']['mobile'] }}@endif
    </p>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="right">Qty</th>
                <th class="right">Price</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($receipt['lines'] as $line)
            <tr>
                <td>
                    {{ $line['product_name'] }}
                    @if (! empty($line['sub_sku']))<br><small>{{ $line['sub_sku'] }}</small>@endif
                </td>
                <td class="right">{{ rtrim(rtrim(number_format((float) $line['quantity'], 4, '.', ''), '0'), '.') }}</td>
                <td class="right">{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $line['unit_price_inc_tax'], 2) }}</td>
                <td class="right">{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $line['line_total'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $receipt['totals']['total_before_tax'], 2) }}</td>
        </tr>
        @if ((float) $receipt['totals']['tax_amount'] > 0)
            <tr>
                <td>Tax</td>
                <td class="right">{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $receipt['totals']['tax_amount'], 2) }}</td>
            </tr>
        @endif
        @if ((float) $receipt['totals']['discount_amount'] > 0)
            <tr>
                <td>Discount</td>
                <td class="right">-{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $receipt['totals']['discount_amount'], 2) }}</td>
            </tr>
        @endif
        @if ((float) $receipt['totals']['shipping_charges'] > 0)
            <tr>
                <td>Shipping</td>
                <td class="right">{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $receipt['totals']['shipping_charges'], 2) }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>Total</strong></td>
            <td class="right"><strong>{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $receipt['totals']['final_total'], 2) }}</strong></td>
        </tr>
    </table>
    <hr>
    <p><strong>Payments:</strong></p>
    @if (count($receipt['payments']) === 0)
        <p><em>No payments recorded.</em></p>
    @else
        <table>
            @foreach ($receipt['payments'] as $payment)
                <tr>
                    <td>
                        {{ $payment['method'] }}
                        @if (! empty($payment['paid_on']))<br><small>{{ $payment['paid_on'] }}</small>@endif
                    </td>
                    <td class="right">{{ $receipt['business']['currency_symbol'] }}{{ number_format((float) $payment['amount'], 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    <p><strong>Status:</strong> {{ $receipt['payment_status'] }}</p>
    <div class="footer">
        <hr>
        <p>Thank you!</p>
    </div>
</body>
</html>
