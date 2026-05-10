<?php

namespace App\Services\Bot;

use App\Models\BotConfiguration;
use App\Models\BotSession;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;

class BotEngine
{
    protected $providers = [];

    public function __construct()
    {
        // Register providers here
        // $this->providers['telegram'] = app(Providers\TelegramProvider::class);
        // $this->providers['whatsapp'] = app(Providers\WhatsAppProvider::class);
    }

    /**
     * Get the active provider for a platform.
     */
    public function getProvider(string $platform): BotProviderInterface
    {
        $config = BotConfiguration::where('platform', $platform)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            throw new Exception("Bot configuration for {$platform} is not active.");
        }

        $providerClass = $this->getProviderClass($platform);
        $provider = app($providerClass);
        
        return $provider->setConfig([
            'api_key' => $config->api_key,
            'api_secret' => $config->api_secret,
            'settings' => $config->settings,
        ]);
    }

    protected function getProviderClass(string $platform): string
    {
        return match ($platform) {
            'telegram' => \App\Services\Bot\Providers\TelegramProvider::class,
            'whatsapp' => \App\Services\Bot\Providers\WhatsAppProvider::class,
            default => throw new Exception("Unsupported bot platform: {$platform}"),
        };
    }

    /**
     * Handle incoming message.
     */
    /**
     * Send a proactive notification to a user via their linked bot account.
     */
    public function notifyUser(int $userId, string $message): bool
    {
        $sessions = BotSession::where('user_id', $userId)->get();
        
        if ($sessions->isEmpty()) {
            return false;
        }

        $success = false;
        foreach ($sessions as $session) {
            try {
                $provider = $this->getProvider($session->platform);
                if ($provider->sendMessage($session->platform_user_id, $message)) {
                    $success = true;
                }
            } catch (\Exception $e) {
                Log::error("Proactive Bot Notification Error ({$session->platform}): " . $e->getMessage());
            }
        }

        return $success;
    }

    public function handleWebhook(string $platform, array $data)
    {
        $provider = $this->getProvider($platform);
        $parsed = $provider->parseWebhook($data);

        if (empty($parsed)) return null;

        $session = BotSession::firstOrCreate(
            [
                'platform' => $platform,
                'platform_user_id' => $parsed['from'],
            ]
        );

        return $this->processIntent($session, $parsed['text'], $provider);
    }

    protected function processIntent(BotSession $session, string $text, BotProviderInterface $provider)
    {
        $text = trim(strtolower($text));

        // 1. Allow PUBLIC commands (Tracking) even for unlinked sessions
        if (str_contains($text, 'track')) {
            return $this->handleTracking($session, $text, $provider);
        }

        if ($text === '/help' || $text === 'help' || str_starts_with($text, '/help ')) {
            return $this->handleHelp($session, $provider);
        }

        // 2. Try auto-linking session from known bot identifiers.
        $this->tryAutoLinkSession($session, $provider);

        // 3. Handle Verification/Linking (Manual)
        if (preg_match('/^verify\s+(\d{6})$/', $text, $matches)) {
            return $this->linkAccount($session, $matches[1], $provider);
        }

        // 4. Routing based on linked user
        if (!$session->user_id) {
            return $provider->sendMessage(
                $session->platform_user_id, 
                "👋 Hello! Your account is not linked.\n\n" .
                "• To **Track** a shipment, type: <code>track [number]</code>\n" .
                "• To **Link** your staff account, type: <code>verify [code]</code>"
            );
        }

        // 5. Command Routing (Linked users only)
        if (str_contains($text, 'stock') || str_starts_with($text, '/stock')) {
            return $this->handleStock($session, $text, $provider);
        }

        if (
            str_contains($text, 'create product')
            || str_contains($text, 'add product')
            || str_starts_with($text, '/product')
            || str_starts_with($text, 'product ')
        ) {
            return $this->handleProductCreation($session, $text, $provider);
        }

        // IMPORTANT: Status update keywords ("delivered", "picked up") MUST be checked
        // BEFORE order creation keywords ("deliver", "order", "send") because
        // "delivered" contains the substring "deliver" and would be misrouted.
        if (str_contains($text, 'delivered') || str_contains($text, 'picked up') || str_contains($text, 'pickup')) {
            return $this->handleJobUpdate($session, $text, $provider);
        }

        if (str_contains($text, 'deliver') || str_contains($text, 'order') || str_contains($text, 'send')) {
            return $this->handleOrderCreation($session, $text, $provider);
        }

        if (str_contains($text, 'jobs') || str_contains($text, 'my tasks')) {
            return $this->handleJobList($session, $text, $provider);
        }

        // 6. AI Fallback (Natural Language Support)
        return $this->handleAiFallback($session, $text, $provider);
    }

    protected function handleAiFallback(BotSession $session, string $text, BotProviderInterface $provider)
    {
        $aiService = app(\App\Services\Ai\AiService::class);
        $context = [];

        // Try to find a tracking number to provide context to the AI
        if (preg_match('/[A-Z]{2,}-\d{8}-\d{4}/i', $text, $matches)) {
            $trackingNumber = $matches[0];
            $shipment = \App\Models\Shipment::where('tracking_number', $trackingNumber)->first();
            if ($shipment) {
                $context['shipment'] = [
                    'tracking_number' => $shipment->tracking_number,
                    'status' => $shipment->status,
                    'receiver_city' => $shipment->receiver_city,
                    'scheduled_pickup' => $shipment->scheduled_pickup_date ? $shipment->scheduled_pickup_date->format('d M, Y') : 'Not scheduled yet',
                    'scheduled_delivery' => $shipment->scheduled_delivery_date ? $shipment->scheduled_delivery_date->format('d M, Y') : 'Not scheduled yet',
                    'current_location' => $shipment->receiver_city . ', ' . $shipment->receiver_state,
                ];
            }
        }

        $response = $aiService->getResponse($text, $context);
        
        return $provider->sendMessage($session->platform_user_id, $response);
    }

    protected function handleJobList(BotSession $session, string $text, BotProviderInterface $provider)
    {
        if (!$session->user_id) {
            return $provider->sendMessage($session->platform_user_id, "❌ You must link your account before viewing jobs.");
        }

        $dispatcher = \App\Models\Dispatcher::where('user_id', $session->user_id)->first();
        if (!$dispatcher) {
            return $provider->sendMessage($session->platform_user_id, "❌ Access Denied: Only drivers can view the job list.");
        }

        $shipments = \App\Models\Shipment::where('dispatcher_id', $dispatcher->id)
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->get();

        if ($shipments->isEmpty()) {
            return $provider->sendMessage($session->platform_user_id, "😎 You have no pending jobs at the moment. Great work!");
        }

        $msg = "📋 <b>Your Current Jobs:</b>\n\n";
        foreach ($shipments as $s) {
            $status = strtoupper(str_replace('_', ' ', $s->status));
            $msg .= "🔹 <code>{$s->tracking_number}</code> ({$status})\n";
            $msg .= "   📍 To: {$s->receiver_city}\n\n";
        }
        $msg .= "To update a status, reply with: <code>delivered [tracking number]</code>";

        return $provider->sendMessage($session->platform_user_id, $msg);
    }

    protected function handleJobUpdate(BotSession $session, string $text, BotProviderInterface $provider)
    {
        if (!$session->user_id) {
            return $provider->sendMessage($session->platform_user_id, "❌ You must link your account before updating job statuses.");
        }

        // Check if user is a driver (dispatcher)
        $dispatcher = \App\Models\Dispatcher::where('user_id', $session->user_id)->first();
        if (!$dispatcher) {
            return $provider->sendMessage($session->platform_user_id, "❌ Access Denied: Only drivers can update job statuses.");
        }

        // Extract ID (Support for Shipment tracking number OR Fulfillment Request REQ-XXXXX)
        preg_match('/([A-Z]{2,}-\d{8}-\d{4}|REQ-\d+)/i', $text, $matches);
        $number = $matches[0] ?? null;

        if (!$number) {
            $exampleAction = str_contains($text, 'delivered') ? 'delivered' : 'picked up';
            return $provider->sendMessage($session->platform_user_id, "Please include the tracking number or order number.\nExample: <code>{$exampleAction} LOG-20260424-0001</code> or <code>{$exampleAction} REQ-00001</code>");
        }

        $isFulfillment = str_starts_with(strtoupper($number), 'REQ-');
        $newStatus = str_contains($text, 'delivered') ? 'delivered' : 'picked_up';

        if ($isFulfillment) {
            $reqId = (int)str_replace('REQ-', '', strtoupper($number));
            $request = \App\Models\FulfillmentRequest::where('id', $reqId)
                ->where('dispatcher_id', $dispatcher->id)
                ->first();

            if (!$request) {
                return $provider->sendMessage($session->platform_user_id, "❌ Order not found or not assigned to you.");
            }

            $updateData = [
                'status' => $newStatus,
                'completed_at' => $newStatus === 'delivered' ? now() : $request->completed_at,
            ];

            if ($newStatus === 'delivered') {
                // Set amount_collected = cod_amount (driver collected full amount)
                $amountCollected = $request->cod_amount ?? 0;
                $updateData['amount_collected'] = $amountCollected;
                $updateData['remittance_amount'] = $amountCollected - ($request->delivery_cost ?? 0);
            }

            $request->update($updateData);

            if ($newStatus === 'delivered') {
                $this->notifyPartnerOrderDelivered($request);
            }

            return $provider->sendMessage($session->platform_user_id, "✅ Order status updated successfully!\n\n📦 <b>{$request->request_number}</b> is now <b>" . strtoupper($newStatus) . "</b>.");
        }

        $shipment = \App\Models\Shipment::where('tracking_number', $number)
            ->where('dispatcher_id', $dispatcher->id)
            ->first();

        if (!$shipment) {
            return $provider->sendMessage($session->platform_user_id, "❌ Shipment not found or not assigned to you.");
        }

        $shipment->update([
            'status' => $newStatus,
            'actual_delivery_date' => $newStatus === 'delivered' ? now() : $shipment->actual_delivery_date,
            'actual_pickup_date' => $newStatus === 'picked_up' ? now() : $shipment->actual_pickup_date,
        ]);
        
        // Log status history
        if (class_exists('\App\Models\ShipmentStatusHistory')) {
            \App\Models\ShipmentStatusHistory::create([
                'shipment_id' => $shipment->id,
                'status' => $newStatus,
                'notes' => 'Updated via ' . ucfirst($session->platform) . ' Bot',
                'recorded_by' => $session->user_id
            ]);
        }

        // If it's a shipment linked to a fulfillment request, notify partner
        if ($newStatus === 'delivered' && $shipment->fulfillmentRequest) {
            $this->notifyPartnerOrderDelivered($shipment->fulfillmentRequest);
        }

        $statusLabel = strtoupper(str_replace('_', ' ', $newStatus));
        return $provider->sendMessage($session->platform_user_id, "✅ Status updated successfully!\n\n📦 <b>{$shipment->tracking_number}</b> is now <b>{$statusLabel}</b>.");
    }

    /**
     * Send a proactive notification to a driver about a new assignment.
     */
    public function notifyDriverAssignment(\App\Models\Shipment $shipment)
    {
        $dispatcher = $shipment->dispatcher;
        if (!$dispatcher || !$dispatcher->user) return;

        $user = $dispatcher->user;
        if (!$user->telegram_id && !$user->whatsapp_number) return;

        $platform = $user->telegram_id ? 'telegram' : 'whatsapp';
        $platformUserId = $user->telegram_id ?? $user->whatsapp_number;
        $provider = $this->getProvider($platform);

        $msg = "🚚 <b>New Job Assigned!</b>\n\n";
        $msg .= "🔢 Number: <code>{$shipment->tracking_number}</code>\n";
        $msg .= "📍 Pickup: {$shipment->sender_address}, {$shipment->sender_city}\n";
        $msg .= "🏠 Delivery: {$shipment->receiver_address}, {$shipment->receiver_city}\n";
        $msg .= "📞 Contact: {$shipment->receiver_phone}\n\n";
        $msg .= "Reply with <code>picked up {$shipment->tracking_number}</code> when you have the package.";

        return $provider->sendMessage($platformUserId, $msg);
    }

    public function notifyDriverFulfillmentAssignment(\App\Models\FulfillmentRequest $request): bool
    {
        $dispatcher = $request->dispatcher;
        if (!$dispatcher || !$dispatcher->user) {
            return false;
        }

        $user = $dispatcher->user;

        $msg = "🚚 <b>New Partner Order Assigned!</b>\n\n";
        $msg .= "🔢 Order No: <code>{$request->request_number}</code>\n";
        $msg .= "📦 Product: " . ($request->partnerProduct?->name ?? 'N/A') . "\n";
        $msg .= "🔢 Quantity: {$request->quantity}\n";
        $msg .= "🏠 Delivery: {$request->delivery_address}\n";
        $msg .= "📞 Phone: " . ($request->delivery_phone ?? 'N/A') . "\n\n";
        $msg .= "Reply with <code>picked up {$request->request_number}</code> when pickup is done.";

        // Try direct route first (user profile link), then fallback to bot sessions.
        if ($user->telegram_id) {
            try {
                $provider = $this->getProvider('telegram');
                if ($provider->sendMessage($user->telegram_id, $msg)) {
                    return true;
                }
            } catch (\Exception $e) {
                Log::error("Driver fulfillment telegram notification failed: " . $e->getMessage());
            }
        }

        if ($user->whatsapp_number) {
            try {
                $provider = $this->getProvider('whatsapp');
                if ($provider->sendMessage($user->whatsapp_number, $msg)) {
                    return true;
                }
            } catch (\Exception $e) {
                Log::error("Driver fulfillment whatsapp notification failed: " . $e->getMessage());
            }
        }

        return $this->notifyUser((int) $user->id, $msg);
    }

    protected function linkAccount(BotSession $session, string $code, BotProviderInterface $provider)
    {
        $user = User::where('bot_verification_code', $code)->first();

        if (!$user) {
            return $provider->sendMessage($session->platform_user_id, "Invalid verification code.");
        }

        $session->update(['user_id' => $user->id]);
        
        // Link user specific field
        if ($session->platform === 'telegram') {
            $user->update(['telegram_id' => $session->platform_user_id]);
        } else {
            $user->update(['whatsapp_number' => $session->platform_user_id]);
        }

        $user->update(['bot_verification_code' => null]);

        return $provider->sendMessage($session->platform_user_id, "Account linked successfully! Welcome, {$user->name}.");
    }

    protected function handleTracking(BotSession $session, string $text, BotProviderInterface $provider)
    {
        // Extract tracking number (e.g. "track LOG-20260424-0001")
        $number = trim(str_replace('track', '', $text));
        
        if (empty($number)) {
            return $provider->sendMessage($session->platform_user_id, "Please provide a tracking number. Example: <code>track CML-20260424-0001</code>");
        }

        // 1. Search in Shipments
        // Sanitize LIKE wildcards to prevent wildcard injection
        $sanitized = str_replace(['%', '_'], ['\%', '\_'], $number);
        $shipment = \App\Models\Shipment::where('tracking_number', 'LIKE', "%{$sanitized}%")->first();
        if ($shipment) {
            $status = strtoupper(str_replace('_', ' ', $shipment->status));
            $msg = "📦 <b>Shipment found:</b>\n\n";
            $msg .= "🔢 Number: <code>{$shipment->tracking_number}</code>\n";
            $msg .= "📊 Status: <b>{$status}</b>\n";
            $msg .= "📍 To: {$shipment->receiver_city}, {$shipment->receiver_state}\n";
            $msg .= "📅 Scheduled: " . ($shipment->scheduled_delivery_date?->format('d M, Y') ?? 'N/A') . "\n";
            
            if ($shipment->status === 'delivered') {
                $msg .= "\n✅ Delivered on: " . ($shipment->actual_delivery_date?->format('d M, Y H:i') ?? 'N/A');
            }

            return $provider->sendMessage($session->platform_user_id, $msg);
        }

        // 2. Search in Fulfillment Requests (Orders)
        // Since request_number is computed, we search by ID if it's in the format REQ-00001
        $reqId = null;
        if (preg_match('/REQ-(\d+)/i', $number, $matches)) {
            $reqId = (int)$matches[1];
        } elseif (is_numeric($number)) {
            $reqId = (int)$number;
        }

        if ($reqId) {
            $request = \App\Models\FulfillmentRequest::find($reqId);
            if ($request) {
                $status = strtoupper(str_replace('_', ' ', $request->status));
                $msg = "🚚 <b>Order found:</b>\n\n";
                $msg .= "🔢 Number: <code>{$request->request_number}</code>\n";
                $msg .= "📊 Status: <b>{$status}</b>\n";
                $msg .= "🏠 Address: {$request->delivery_address}\n";
                $msg .= "📦 Qty: {$request->quantity}\n";
                
                return $provider->sendMessage($session->platform_user_id, $msg);
            }
        }

        return $provider->sendMessage($session->platform_user_id, "❌ No shipment or order found with number: <b>{$number}</b>");
    }

    protected function handleStock(BotSession $session, string $text, BotProviderInterface $provider)
    {
        // Extract SKU or name (e.g. "stock SKU-001" or "/stock SKU-001")
        $query = trim(preg_replace('/^\/?stock\s+/i', '', $text));

        if (empty($query) || $query === 'stock' || $query === '/stock') {
            return $provider->sendMessage($session->platform_user_id, "Please provide a SKU or Product Name. Example: <code>/stock SKU-123</code>");
        }

        $user = $session->user;
        $partnerCustomers = [];

        // If user is a partner, restrict search to their products
        if ($user) {
            $partnerCustomers = \App\Models\PartnerCustomer::where('partner_id', $user->id)->pluck('id')->toArray();
        }

        $productQuery = \App\Models\PartnerProduct::where('is_approved', true)
            ->where(function($q) use ($query) {
                $q->where('sku', 'LIKE', "%{$query}%")
                  ->orWhere('name', 'LIKE', "%{$query}%");
            });

        if (!empty($partnerCustomers)) {
            $productQuery->whereIn('partner_customer_id', $partnerCustomers);
        }

        $product = $productQuery->first();

        if ($product) {
            $msg = "📦 <b>Stock Info:</b>\n\n";
            $msg .= "📛 Name: <b>{$product->name}</b>\n";
            $msg .= "🔢 SKU: <code>{$product->sku}</code>\n";
            $msg .= "📊 Quantity: <b>{$product->quantity}</b>\n";
            $msg .= "📍 Location: " . ($product->warehouse_location ?? 'N/A') . "\n";
            
            if ($product->isLowStock()) {
                $msg .= "\n⚠️ <b>Warning:</b> Low stock level!";
            }

            return $provider->sendMessage($session->platform_user_id, $msg);
        }

        return $provider->sendMessage($session->platform_user_id, "❌ No approved product found matching: <b>{$query}</b>");
    }

    protected function handleHelp(BotSession $session, BotProviderInterface $provider)
    {
        $msg = "🤖 <b>Logistico Bot Commands</b>\n\n";

        $msg .= "<b>━━ Partner Commands ━━</b>\n\n";

        $msg .= "📦 <b>Add Product</b>\n";
        $msg .= "<code>add product [SKU optional] [Product Name] qty [Quantity] cost [Unit Cost optional]</code>\n";
        $msg .= "Example: <code>add product SKU-001 Rice 50kg Bag qty 100 cost 2500</code>\n\n";

        $msg .= "🚚 <b>Create Delivery Order</b>\n";
        $msg .= "<code>deliver [Qty] units of [Product Name or SKU] to [Full Address] phone [Phone Number] for [Customer Name]</code>\n";
        $msg .= "Examples:\n";
        $msg .= "• <code>deliver 10 units of SKU-001 to 123 Main Street, Lagos phone 08012345678 for John Doe</code>\n";
        $msg .= "• <code>deliver 5 units of Rice 50kg Bag to 45 Broad St, Ikeja phone 09098765432 for Amina Store</code>\n";
        $msg .= "• <code>deliver 3 units of SKU-002 to 10 Allen Avenue, Lagos</code> (phone & name optional)\n\n";

        $msg .= "<b>━━ General Commands ━━</b>\n\n";

        $msg .= "🔍 <b>Track Order/Shipment</b>\n";
        $msg .= "<code>track [tracking number or REQ number]</code>\n";
        $msg .= "Example: <code>track REQ-00012</code>\n\n";

        $msg .= "📊 <b>Check Stock</b>\n";
        $msg .= "<code>/stock [SKU or Product Name]</code>\n";
        $msg .= "Example: <code>/stock SKU-001</code>\n\n";

        $msg .= "<b>━━ Driver Commands ━━</b>\n\n";

        $msg .= "✅ <b>Update Delivery Status</b>\n";
        $msg .= "<code>delivered [REQ number or tracking number]</code>\n";
        $msg .= "<code>picked up [REQ number or tracking number]</code>\n";
        $msg .= "Example: <code>delivered REQ-00012</code>\n\n";

        $msg .= "📋 <b>View My Jobs</b>\n";
        $msg .= "<code>jobs</code> or <code>my tasks</code>\n\n";

        $msg .= "<b>━━ Account Linking ━━</b>\n\n";

        $msg .= "🔗 <b>Link Account</b>\n";
        $msg .= "<code>verify [6-digit-code]</code>\n\n";

        $msg .= "💡 If a command fails, ensure your Telegram/WhatsApp is mapped to your partner profile.";

        return $provider->sendMessage($session->platform_user_id, $msg);
    }

    /**
     * Handle quick order creation from natural language.
     * Example: "Deliver 5 units of Product A to 123 Main St."
     */
    protected function handleOrderCreation(BotSession $session, string $text, BotProviderInterface $provider)
    {
        $user = $this->resolvePartnerUserForSession($session);
        if (!$user) {
            return $provider->sendMessage(
                $session->platform_user_id,
                "❌ Unable to identify your partner account for this bot number.\nPlease contact support to map your phone/telegram to your partner profile."
            );
        }
        
        $partnerCustomerIds = $this->getPartnerCustomerIdsForUser($user);
        if (empty($partnerCustomerIds)) {
            return $provider->sendMessage(
                $session->platform_user_id,
                "❌ Error: Unable to prepare your partner inventory profile. Please ensure at least one warehouse is configured."
            );
        }

        // Parsing logic: "Deliver [qty] units of [Product] to [Address] phone [Phone] for [Customer Name]"
        // Phone and customer name are optional
        // Example: deliver 5 units of SKU-001 to 123 Main St phone 08012345678 for John Doe
        preg_match('/(?:deliver|send|order)\s+(\d+)\s*(?:units?|pcs)?\s*(?:of)?\s+(.+?)\s+to\s+(.+)/i', $text, $matches);

        if (count($matches) < 4) {
            return $provider->sendMessage($session->platform_user_id, 
                "📝 <b>How to create an order:</b>\n\n" .
                "Reply with:\n<code>deliver [Qty] units of [Product] to [Address] phone [Phone] for [Customer Name]</code>\n\n" .
                "Examples:\n" .
                "• <code>deliver 10 units of SKU-001 to 123 Main Street, Lagos phone 08012345678 for John Doe</code>\n" .
                "• <code>deliver 5 units of Rice Bag to 45 Broad St, Ikeja</code> (phone & name optional)"
            );
        }

        $quantity = (int)$matches[1];
        $productQuery = trim($matches[2]);
        $addressPart = trim($matches[3]);

        // Extract optional phone number: "phone 08012345678" or "phone: 08012345678"
        $customerPhone = null;
        if (preg_match('/\s+phone\s*[:\-]?\s*([\d\+\-\s\(\)]{7,20})/i', $addressPart, $phoneMatch)) {
            $customerPhone = trim($phoneMatch[1]);
            // Remove phone part from the address string
            $addressPart = trim(preg_replace('/\s+phone\s*[:\-]?\s*[\d\+\-\s\(\)]{7,20}/i', '', $addressPart));
        }

        // Extract optional customer name: "for John Doe"
        $customerName = null;
        if (preg_match('/\s+for\s+(.+)$/i', $addressPart, $nameMatch)) {
            $customerName = trim($nameMatch[1]);
            // Remove name part from the address string
            $addressPart = trim(preg_replace('/\s+for\s+.+$/i', '', $addressPart));
        }

        $address = $addressPart;

        // Find product
        $product = \App\Models\PartnerProduct::whereIn('partner_customer_id', $partnerCustomerIds)
            ->where('is_approved', true)
            ->where(function($q) use ($productQuery) {
                $q->where('sku', 'LIKE', "%{$productQuery}%")
                  ->orWhere('name', 'LIKE', "%{$productQuery}%");
            })
            ->first();

        if (!$product) {
            return $provider->sendMessage($session->platform_user_id, "❌ Could not find an approved product matching: <b>{$productQuery}</b>");
        }

        if ($product->quantity < $quantity) {
            return $provider->sendMessage($session->platform_user_id, "❌ Insufficient stock. Current quantity: <b>{$product->quantity}</b>");
        }

        $partnerCustomer = $product->partnerCustomer;
        $resolvedStaffId = $this->resolveStaffIdForOrder($partnerCustomer, $user);
        if (!$partnerCustomer || !$resolvedStaffId) {
            return $provider->sendMessage(
                $session->platform_user_id,
                "❌ Your partner profile has no assigned operations staff yet.\nPlease ask admin to assign staff before creating delivery orders."
            );
        }

        // Use provided phone, or fall back to partner customer/partner/user phone
        $deliveryPhone = $customerPhone
            ?: $partnerCustomer->customer_phone
            ?: $partnerCustomer->partner?->phone
            ?: $user->phone
            ?: 'N/A';

        // Calculate COD from product cost
        $codAmount = ($product->unit_cost ?? 0) * $quantity;

        // Create Fulfillment Request
        $request = \App\Models\FulfillmentRequest::create([
            'partner_customer_id' => $product->partner_customer_id,
            'partner_product_id' => $product->id,
            'staff_id' => $resolvedStaffId,
            'quantity' => $quantity,
            'delivery_address' => $address,
            'delivery_phone' => $deliveryPhone,
            'delivery_notes' => $customerName,
            'status' => 'pending',
            'requested_by' => $user->id,
            'requested_at' => now(),
            'cod_amount' => $codAmount,
            'remittance_amount' => $codAmount, // delivery_cost is 0 initially
        ]);

        // Decrement product quantity
        $product->decrement('quantity', $quantity);

        $msg = "✅ <b>Order Created Successfully!</b>\n\n";
        $msg .= "🔢 Order No: <code>{$request->request_number}</code>\n";
        $msg .= "📦 Product: {$product->name}\n";
        $msg .= "🔢 Quantity: {$quantity}\n";
        $msg .= "📍 Address: {$address}\n";
        if ($customerPhone) {
            $msg .= "📞 Phone: {$deliveryPhone}\n";
        }
        if ($customerName) {
            $msg .= "👤 Customer: {$customerName}\n";
        }
        $msg .= "\nYour order is now <b>PENDING</b> and will be processed by our team shortly.";

        return $provider->sendMessage($session->platform_user_id, $msg);
    }

    protected function handleProductCreation(BotSession $session, string $text, BotProviderInterface $provider)
    {
        $user = $this->resolvePartnerUserForSession($session);
        if (!$user) {
            return $provider->sendMessage(
                $session->platform_user_id,
                "❌ Unable to identify your partner account for this bot number.\nPlease contact support to map your phone/telegram to your partner profile."
            );
        }

        $partnerCustomer = $this->resolveOrCreatePartnerInventoryProfile($user);
        if (!$partnerCustomer) {
            return $provider->sendMessage(
                $session->platform_user_id,
                "❌ Error: Unable to prepare your partner inventory profile. Please ensure at least one warehouse is configured."
            );
        }

        // Example:
        // add product SKU-001 Rice 50kg Bag qty 100 cost 2500
        preg_match(
            '/(?:add|create)\s+product\s+(?:sku\s*[:\-]?\s*([a-z0-9\-_]+)\s+)?(.+?)\s+(?:qty|quantity)\s*[:\-]?\s*(\d+)(?:\s+(?:cost|price|unit_cost)\s*[:\-]?\s*([0-9]+(?:\.[0-9]{1,2})?))?/i',
            $text,
            $matches
        );

        if (count($matches) < 4) {
            return $provider->sendMessage(
                $session->platform_user_id,
                "📝 <b>How to add a product:</b>\n\n" .
                "Reply with: <code>add product [SKU optional] [Product Name] qty [Quantity] cost [Unit Cost optional]</code>\n\n" .
                "Example: <code>add product SKU-001 Rice 50kg Bag qty 100 cost 2500</code>"
            );
        }

        $sku = isset($matches[1]) && trim($matches[1]) !== '' ? strtoupper(trim($matches[1])) : null;
        $name = trim($matches[2]);
        $quantity = (int)$matches[3];
        $unitCost = isset($matches[4]) ? (float)$matches[4] : 0;

        $product = \App\Models\PartnerProduct::create([
            'partner_customer_id' => $partnerCustomer->id,
            'sku' => $sku,
            'name' => $name,
            'quantity' => max($quantity, 0),
            'unit_cost' => max($unitCost, 0),
            'reorder_level' => 10,
            'is_active' => true,
            'is_approved' => false,
        ]);

        return $provider->sendMessage(
            $session->platform_user_id,
            "✅ <b>Product Created Successfully!</b>\n\n" .
            "📦 Product: <b>{$product->name}</b>\n" .
            "🔢 SKU: <code>" . ($product->sku ?? 'N/A') . "</code>\n" .
            "📊 Quantity: <b>{$product->quantity}</b>\n" .
            "💰 Unit Cost: <b>{$product->unit_cost}</b>\n\n" .
            "Your product is pending approval before it can be used for new orders."
        );
    }

    protected function tryAutoLinkSession(BotSession $session, BotProviderInterface $provider): void
    {
        if ($session->user_id) {
            return;
        }

        $platformUserId = trim((string) $session->platform_user_id);
        if ($platformUserId === '') {
            return;
        }

        $user = null;
        if ($session->platform === 'telegram') {
            $user = \App\Models\User::where('telegram_id', $platformUserId)->first();
        } elseif ($session->platform === 'whatsapp') {
            $user = \App\Models\User::where('whatsapp_number', $platformUserId)
                ->orWhere('phone', $platformUserId)
                ->first();
        }

        if (!$user) {
            return;
        }

        $session->update(['user_id' => $user->id]);

        if ($session->platform === 'telegram' && !$user->telegram_id) {
            $user->update(['telegram_id' => $platformUserId]);
        }

        if ($session->platform === 'whatsapp' && !$user->whatsapp_number) {
            $user->update(['whatsapp_number' => $platformUserId]);
        }

        $provider->sendMessage(
            $session->platform_user_id,
            "👋 Welcome back, <b>{$user->name}</b>! Your bot account has been recognized automatically."
        );
    }

    protected function resolvePartnerUserForSession(BotSession $session): ?User
    {
        if ($session->user_id) {
            $user = $session->user;
            if ($user) {
                return $user;
            }
        }

        $platformUserId = trim((string) $session->platform_user_id);
        if ($platformUserId === '') {
            return null;
        }

        $userQuery = \App\Models\User::query();
        if ($session->platform === 'telegram') {
            $userQuery->where('telegram_id', $platformUserId);
        } else {
            $userQuery->where(function ($q) use ($platformUserId) {
                $q->where('whatsapp_number', $platformUserId)
                    ->orWhere('phone', $platformUserId);
            });
        }

        $user = $userQuery->first();
        if (!$user) {
            return null;
        }

        if (!$session->user_id) {
            $session->update(['user_id' => $user->id]);
        }

        return $user;
    }

    protected function resolveOrCreatePartnerInventoryProfile(User $user): ?\App\Models\PartnerCustomer
    {
        $existing = \App\Models\PartnerCustomer::where('partner_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $warehouse = \App\Models\Warehouse::where('is_active', true)->first() ?? \App\Models\Warehouse::first();
        if (!$warehouse) {
            return null;
        }

        $fallbackPhone = $user->phone ?: ('partner-' . $user->id . '-' . substr((string) time(), -6));
        $customer = \App\Models\Customer::firstOrCreate(
            ['phone' => $fallbackPhone],
            [
                'customer_code' => $this->generateCustomerCode(),
                'name' => $user->company ?: $user->name,
                'email' => $user->email,
                'address' => 'Partner-managed account',
                'city' => 'N/A',
                'state' => 'N/A',
                'type' => $user->company ? 'business' : 'individual',
                'company_name' => $user->company,
                'created_by' => $user->id,
            ]
        );

        return \App\Models\PartnerCustomer::create([
            'customer_id' => $customer->id,
            'partner_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'staff_id' => null,
            'storage_type' => 'free',
            'storage_rate' => 0,
            'notes' => 'Auto-created from bot for partner inventory operations.',
            'created_by' => $user->id,
            'customer_name' => $user->company ?: $user->name,
            'customer_phone' => $user->phone,
            'customer_email' => $user->email,
        ]);
    }

    protected function getPartnerCustomerIdsForUser(User $user): array
    {
        $ids = \App\Models\PartnerCustomer::where('partner_id', $user->id)->pluck('id')->all();
        if (!empty($ids)) {
            return $ids;
        }

        $created = $this->resolveOrCreatePartnerInventoryProfile($user);
        if (!$created) {
            return [];
        }

        return [$created->id];
    }

    protected function resolveStaffIdForOrder(?\App\Models\PartnerCustomer $partnerCustomer, User $user): ?int
    {
        if ($partnerCustomer && $partnerCustomer->staff_id) {
            return (int) $partnerCustomer->staff_id;
        }

        // Fallback: any assigned staff under this partner.
        $fallbackStaffId = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->whereNotNull('staff_id')
            ->value('staff_id');

        if ($fallbackStaffId) {
            return (int) $fallbackStaffId;
        }

        return null;
    }

    protected function generateCustomerCode(): string
    {
        do {
            $code = 'CUST-' . now()->format('Ymd') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (\App\Models\Customer::where('customer_code', $code)->exists());

        return $code;
    }

    /**
     * Notify partner when an order is delivered.
     */
    public function notifyPartnerOrderDelivered(\App\Models\FulfillmentRequest $request)
    {
        $partnerCustomer = $request->partnerCustomer;
        if (!$partnerCustomer || !$partnerCustomer->partner_id) return;

        $user = $partnerCustomer->partner;
        $platformUserId = $user->whatsapp_number ?? $user->telegram_id;
        
        if (!$platformUserId) return;

        $platform = $user->whatsapp_number ? 'whatsapp' : 'telegram';
        $provider = $this->getProvider($platform);

        $msg = "🎉 <b>Order Delivered!</b>\n\n";
        $msg .= "🔢 Order No: <code>{$request->request_number}</code>\n";
        $msg .= "📦 Product: {$request->partnerProduct?->name}\n";
        $msg .= "🔢 Quantity: {$request->quantity}\n";
        $msg .= "🏠 Destination: {$request->delivery_address}\n\n";
        $msg .= "Your customer has received their package. Thank you for using Logistico!";

        return $provider->sendMessage($platformUserId, $msg);
    }
}
