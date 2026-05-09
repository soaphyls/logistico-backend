<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Receipt {{ $payment->reference_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #16a34a; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .receipt-box { background: white; border: 2px solid #16a34a; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .receipt-header { text-align: center; border-bottom: 2px solid #16a34a; padding-bottom: 15px; margin-bottom: 15px; }
        .receipt-header h2 { color: #16a34a; margin: 0; }
        .receipt-header p { margin: 5px 0 0 0; color: #666; }
        .receipt-details { display: flex; justify-content: space-between; margin: 10px 0; }
        .receipt-details .label { color: #666; }
        .receipt-details .value { font-weight: bold; }
        .amount-box { background: #dcfce7; border: 2px solid #16a34a; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0; }
        .amount-box .label { color: #16a34a; font-size: 14px; text-transform: uppercase; }
        .amount-box .amount { font-size: 32px; font-weight: bold; color: #16a34a; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>PAYMENT RECEIVED</h1>
            <p>Thank you for your payment!</p>
        </div>
        
        <div class="content">
            <p>Dear {{ $customer->name }},</p>
            
            <p>We have received your payment. Here is your official receipt:</p>
            
            <div class="receipt-box">
                <div class="receipt-header">
                    <h2>RECEIPT</h2>
                    <p>{{ $payment->reference_number }}</p>
                </div>
                
                <div class="receipt-details">
                    <span class="label">Invoice Number:</span>
                    <span class="value">{{ $invoice->invoice_number }}</span>
                </div>
                <div class="receipt-details">
                    <span class="label">Payment Date:</span>
                    <span class="value">{{ $payment->payment_date->format('F j, Y') }}</span>
                </div>
                <div class="receipt-details">
                    <span class="label">Payment Method:</span>
                    <span class="value">{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</span>
                </div>
                @if($payment->reference_number)
                <div class="receipt-details">
                    <span class="label">Reference:</span>
                    <span class="value">{{ $payment->reference_number }}</span>
                </div>
                @endif
            </div>
            
            <div class="amount-box">
                <div class="label">Amount Paid</div>
                <div class="amount">₦{{ number_format($payment->amount, 2) }}</div>
            </div>
            
            @if($invoice->balance_due > 0)
            <div class="receipt-box">
                <div class="receipt-details">
                    <span class="label">Invoice Total:</span>
                    <span class="value">₦{{ number_format($invoice->total_amount, 2) }}</span>
                </div>
                <div class="receipt-details">
                    <span class="label">Amount Paid:</span>
                    <span class="value">-₦{{ number_format($payment->amount, 2) }}</span>
                </div>
                <div class="receipt-details">
                    <span class="label">Balance Due:</span>
                    <span class="value">₦{{ number_format($invoice->balance_due, 2) }}</span>
                </div>
            </div>
            @else
            <div style="text-align: center; padding: 15px; background: #dcfce7; border-radius: 8px; color: #16a34a; font-weight: bold;">
                ✓ Invoice Fully Paid
            </div>
            @endif
            
            <p style="margin-top: 20px;">If you have any questions about this receipt, please contact us.</p>
        </div>
        
        <div class="footer">
            <p>Thank you for choosing our logistics services!</p>
            <p>&copy; {{ date('Y') }} Logistico. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
