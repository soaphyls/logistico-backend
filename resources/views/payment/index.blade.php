<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Invoice - {{ $invoice->invoice_number }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .brand-gradient { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-2xl mx-auto py-12 px-4">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-orange-500">{{ $companySettings->company_name ?? 'Logistico' }}</h1>
            <p class="text-gray-500 mt-1">Secure Payment Portal</p>
        </div>

        <!-- Invoice Summary Card -->
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-6">
            <div class="flex justify-between items-start mb-6">
                <div>
                    <p class="text-sm text-gray-500">Invoice Number</p>
                    <p class="text-xl font-bold text-gray-800">{{ $invoice->invoice_number }}</p>
                </div>
                <div class="text-right">
                    @if($invoice->status === 'paid')
                        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-medium">Paid</span>
                    @elseif($invoice->status === 'partial')
                        <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-sm font-medium">Partial</span>
                    @else
                        <span class="px-3 py-1 bg-orange-100 text-orange-700 rounded-full text-sm font-medium">Pending</span>
                    @endif
                </div>
            </div>

            <div class="border-t border-b border-gray-100 py-4 mb-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-500">Customer</p>
                        <p class="font-medium text-gray-800">{{ $invoice->customer->name }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Due Date</p>
                        <p class="font-medium text-gray-800">{{ $invoice->due_date ? $invoice->due_date->format('M d, Y') : 'No due date' }}</p>
                    </div>
                </div>
            </div>

            <!-- Amount Summary -->
            <div class="space-y-3">
                <div class="flex justify-between">
                    <span class="text-gray-600">Subtotal</span>
                    <span class="font-medium">₦{{ number_format($invoice->subtotal, 2) }}</span>
                </div>
                @if($invoice->tax_amount > 0)
                <div class="flex justify-between">
                    <span class="text-gray-600">Tax ({{ $invoice->tax_rate }}%)</span>
                    <span class="font-medium">₦{{ number_format($invoice->tax_amount, 2) }}</span>
                </div>
                @endif
                @if($invoice->discount > 0)
                <div class="flex justify-between">
                    <span class="text-gray-600">Discount</span>
                    <span class="font-medium text-green-600">-₦{{ number_format($invoice->discount, 2) }}</span>
                </div>
                @endif
                <div class="flex justify-between pt-3 border-t border-gray-200">
                    <span class="text-lg font-medium">Total Amount</span>
                    <span class="text-lg font-bold text-orange-500">₦{{ number_format($invoice->total_amount, 2) }}</span>
                </div>
                @if($invoice->amount_paid > 0)
                <div class="flex justify-between text-green-600">
                    <span>Amount Paid</span>
                    <span>-₦{{ number_format($invoice->amount_paid, 2) }}</span>
                </div>
                <div class="flex justify-between pt-3 border-t border-gray-200">
                    <span class="font-medium">Balance Due</span>
                    <span class="font-bold text-red-500">₦{{ number_format($invoice->balance_due, 2) }}</span>
                </div>
                @endif
            </div>
        </div>

        <!-- Payment Form -->
        @if($invoice->status !== 'paid')
        <div class="bg-white rounded-2xl shadow-lg p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Make Payment</h2>
            
            <form id="paymentForm" class="space-y-6">
                @csrf
                
                <!-- Payer Info -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Your Name *</label>
                        <input type="text" name="payer_name" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                            placeholder="John Doe">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                        <input type="email" name="payer_email" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                            placeholder="john@example.com" value="{{ $invoice->customer->email }}">
                    </div>
                </div>

                <!-- Amount -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Amount to Pay *</label>
                    <input type="number" name="amount" step="0.01" required min="1" max="{{ $invoice->balance_due }}"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                        placeholder="0.00" value="{{ $invoice->balance_due }}">
                    <p class="text-sm text-gray-500 mt-1">Balance due: ₦{{ number_format($invoice->balance_due, 2) }}</p>
                </div>

                <!-- Payment Method -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method *</label>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <label class="border border-gray-200 rounded-lg p-4 cursor-pointer hover:border-orange-500 hover:bg-orange-50 transition text-center">
                            <input type="radio" name="payment_method" value="card" class="sr-only peer">
                            <div class="peer-checked:text-orange-500">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                </svg>
                                <span class="text-sm font-medium">Card</span>
                            </div>
                        </label>
                        <label class="border border-gray-200 rounded-lg p-4 cursor-pointer hover:border-orange-500 hover:bg-orange-50 transition text-center">
                            <input type="radio" name="payment_method" value="bank_transfer" class="sr-only peer">
                            <div class="peer-checked:text-orange-500">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                </svg>
                                <span class="text-sm font-medium">Bank Transfer</span>
                            </div>
                        </label>
                        <label class="border border-gray-200 rounded-lg p-4 cursor-pointer hover:border-orange-500 hover:bg-orange-50 transition text-center">
                            <input type="radio" name="payment_method" value="pos" class="sr-only peer">
                            <div class="peer-checked:text-orange-500">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                </svg>
                                <span class="text-sm font-medium">POS</span>
                            </div>
                        </label>
                        <label class="border border-gray-200 rounded-lg p-4 cursor-pointer hover:border-orange-500 hover:bg-orange-50 transition text-center">
                            <input type="radio" name="payment_method" value="cash" class="sr-only peer">
                            <div class="peer-checked:text-orange-500">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                                <span class="text-sm font-medium">Cash</span>
                            </div>
                        </label>
                        <label class="border border-gray-200 rounded-lg p-4 cursor-pointer hover:border-orange-500 hover:bg-orange-50 transition text-center">
                            <input type="radio" name="payment_method" value="cheque" class="sr-only peer">
                            <div class="peer-checked:text-orange-500">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <span class="text-sm font-medium">Cheque</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Reference Number -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Reference Number (Optional)</label>
                    <input type="text" name="reference_number"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                        placeholder="e.g., TRF123456">
                </div>

                <!-- Submit Button -->
                <button type="submit" id="submitBtn"
                    class="w-full brand-gradient text-white py-4 rounded-lg font-semibold hover:opacity-90 transition flex items-center justify-center gap-2">
                    <span>Pay ₦<span id="payAmount">{{ number_format($invoice->balance_due, 2) }}</span></span>
                </button>
            </form>

            <!-- Success Message (Hidden by default) -->
            <div id="successMessage" class="hidden text-center py-8">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold text-gray-800 mb-2">Payment Successful!</h3>
                <p class="text-gray-600 mb-4">Your payment has been processed successfully.</p>
                <p class="text-sm text-gray-500">A receipt has been sent to your email.</p>
            </div>
        </div>
        @else
        <div class="bg-white rounded-2xl shadow-lg p-6 text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-2">Invoice Fully Paid</h3>
            <p class="text-gray-600">This invoice has been paid in full. Thank you!</p>
        </div>
        @endif

        <!-- Company Info -->
        <div class="mt-8 text-center text-sm text-gray-500">
            @if($companySettings->phone)
            <p>Phone: {{ $companySettings->phone }}</p>
            @endif
            @if($companySettings->email)
            <p>Email: {{ $companySettings->email }}</p>
            @endif
            @if($companySettings->address)
            <p>Address: {{ $companySettings->address }}</p>
            @endif
        </div>
    </div>

    <script>
        const form = document.getElementById('paymentForm');
        const amountInput = document.querySelector('input[name="amount"]');
        const payAmountSpan = document.getElementById('payAmount');
        const submitBtn = document.getElementById('submitBtn');
        const successMessage = document.getElementById('successMessage');
        
        // Check for callback status
        const urlParams = new URLSearchParams(window.location.search);
        const paymentStatus = urlParams.get('status');
        
        if (paymentStatus === 'success') {
            form.classList.add('hidden');
            successMessage.classList.remove('hidden');
        } else if (paymentStatus === 'error') {
            const errorMessage = urlParams.get('message') || 'Payment failed';
            alert(errorMessage);
        }

        if (amountInput) {
            amountInput.addEventListener('input', (e) => {
                payAmountSpan.textContent = parseFloat(e.target.value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            });
        }

        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
                const token = '{{ $invoice->payment_link }}';
                const invoiceId = '{{ $invoice->id }}';
                
                // For card payments, initialize gateway
                if (paymentMethod === 'card') {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Redirecting...';

                    const formData = new FormData(form);
                    
                    try {
                        const response = await fetch(`/pay/${invoiceId}/${token}/initialize`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json',
                            },
                            body: formData,
                        });

                        const data = await response.json();

                        if (data.success && data.checkout_url) {
                            // Redirect to payment gateway
                            window.location.href = data.checkout_url;
                        } else {
                            alert(data.message || 'Failed to initialize payment. Please try again.');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = 'Pay ₦<span id="payAmount">' + payAmountSpan.textContent + '</span>';
                        }
                    } catch (error) {
                        alert('An error occurred. Please try again.');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'Pay ₦<span id="payAmount">' + payAmountSpan.textContent + '</span>';
                    }
                    return;
                }
                
                // For non-card payments, process directly
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...';

                const formData = new FormData(form);

                try {
                    const response = await fetch(`/pay/${invoiceId}/${token}`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                        body: formData,
                    });

                    const data = await response.json();

                    if (data.success) {
                        form.classList.add('hidden');
                        successMessage.classList.remove('hidden');
                    } else {
                        alert(data.error || 'Payment failed. Please try again.');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'Pay ₦<span id="payAmount">' + payAmountSpan.textContent + '</span>';
                    }
                } catch (error) {
                    alert('An error occurred. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Pay ₦<span id="payAmount">' + payAmountSpan.textContent + '</span>';
                }
            });
        }
    </script>
</body>
</html>
