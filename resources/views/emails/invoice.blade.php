<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f97316; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #eee; }
        .total { font-size: 18px; font-weight: bold; color: #f97316; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>INVOICE</h1>
            <p>{{ $invoice->invoice_number }}</p>
        </div>
        
        <div class="content">
            <p>Dear {{ $customer->name }},</p>
            
            <p>Thank you for your business. Please find your invoice details below:</p>
            
            <table>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
                <tr>
                    <td>Shipping Cost - Shipment #{{ $invoice->shipment->tracking_number ?? 'N/A' }}</td>
                    <td>₦{{ number_format($invoice->subtotal, 2) }}</td>
                </tr>
                @if($invoice->tax_amount > 0)
                <tr>
                    <td>Tax ({{ $invoice->tax_rate }}%)</td>
                    <td>₦{{ number_format($invoice->tax_amount, 2) }}</td>
                </tr>
                @endif
                @if($invoice->discount > 0)
                <tr>
                    <td>Discount</td>
                    <td>-₦{{ number_format($invoice->discount, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td><strong>Total Amount</strong></td>
                    <td class="total">₦{{ number_format($invoice->total_amount, 2) }}</td>
                </tr>
            </table>
            
            @if($invoice->due_date)
            <p><strong>Due Date:</strong> {{ $invoice->due_date->format('F j, Y') }}</p>
            @endif
            
            <p>Please make payment at your earliest convenience to keep your account in good standing.</p>
            
            <p>If you have any questions about this invoice, please contact us.</p>
        </div>
        
        <div class="footer">
            <p>Thank you for choosing our logistics services!</p>
            <p>&copy; {{ date('Y') }} Logistico. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
