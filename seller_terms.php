<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => 'method_not_allowed',
            'message' => 'Only GET and OPTIONS requests are allowed.',
        ],
        'meta' => [
            'api_version' => 'mobile-v1',
            'generated_at' => gmdate('c'),
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(200);

echo json_encode([
    'ok' => true,
    'data' => [
        'terms' => [
            'version' => 'seller_terms_v1_2026_05_14',
            'title' => 'Bettavaro Seller Terms and Marketplace Rules',
            'last_updated' => '2026-05-14',
            'language' => 'en',
            'summary' => 'These Seller Terms explain the rules and obligations for selling on the Bettavaro marketplace. Sellers must provide accurate information, list only fish they own or are authorized to sell, describe fish honestly, fulfill accepted orders responsibly, cooperate with marketplace support, and accept the platform fee, payout, refund, dispute, fraud review, and account safety rules.',
            'sections' => [
                [
                    'id' => 'seller_identity',
                    'title' => 'Seller Identity and Accurate Information',
                    'body' => 'Sellers must provide accurate farm, business, contact, location, and profile information. Seller names, farm details, payment details, and shipping information must be truthful and kept current. Bettavaro may request additional information to confirm seller identity, marketplace safety, order handling ability, or policy compliance.',
                ],
                [
                    'id' => 'listing_accuracy_fish_health',
                    'title' => 'Listing Accuracy and Fish Health',
                    'body' => 'Sellers may list only fish they own or are authorized to sell. Each fish must be healthy, legally sellable, and accurately described at the time of listing. Sellers must not list sick, injured, unavailable, misidentified, or unsafe fish. Important details such as species, strain, size, sex, age, condition, defects, breeding status, and availability must be clear and honest.',
                ],
                [
                    'id' => 'real_photos_honest_description',
                    'title' => 'Real Photos and Honest Description',
                    'body' => 'Photos and videos must represent the real fish being offered, unless the listing clearly states that representative media is being used for a group or batch. Sellers must not mislead buyers with fake photos, excessive editing, fake strain names, false champion claims, copied media, hidden defects, or false availability. Descriptions must be written to help buyers make informed decisions.',
                ],
                [
                    'id' => 'pricing_offers_auction_responsibility',
                    'title' => 'Pricing, Offers, and Auction Responsibility',
                    'body' => 'Sellers are responsible for setting accurate prices, shipping charges, offer rules, reserve prices, and auction terms. Sellers must honor accepted offers, confirmed purchases, and winning auctions. A seller may not cancel a valid order only to request a higher price, sell outside the marketplace, avoid fees, or favor another buyer.',
                ],
                [
                    'id' => 'packaging_shipping_responsibility',
                    'title' => 'Packaging and Shipping Responsibility',
                    'body' => 'Sellers must pack fish safely and humanely using appropriate bags, water volume, insulation, temperature control, oxygen or air where appropriate, and secure outer packaging. Sellers must consider weather, transit time, destination rules, carrier limits, and live animal safety before shipping. Sellers remain responsible for preparing shipments in a way that matches the agreed handling time and marketplace rules.',
                ],
                [
                    'id' => 'order_fulfillment_tracking',
                    'title' => 'Order Fulfillment and Tracking',
                    'body' => 'Sellers must fulfill paid orders within the agreed handling time and communicate promptly if a shipping delay is necessary for fish safety or carrier availability. Sellers must provide tracking where available and must update the buyer or Bettavaro support with shipment status when requested. Orders should not be marked shipped before the package is actually accepted by the carrier or shipping service.',
                ],
                [
                    'id' => 'buyer_communication',
                    'title' => 'Buyer Communication',
                    'body' => 'Sellers must communicate with buyers professionally, respectfully, and honestly. Messages should answer reasonable questions about the fish, order status, shipping schedule, and after-sale concerns. Sellers must not harass buyers, pressure buyers to complete transactions outside Bettavaro, share misleading claims, or use abusive language.',
                ],
                [
                    'id' => 'refund_cancellation_dispute_cooperation',
                    'title' => 'Refund, Cancellation, and Dispute Cooperation',
                    'body' => 'Sellers must cooperate with Bettavaro refund, cancellation, and dispute processes. This may include providing photos, videos, packing proof, shipment records, tracking details, communication history, or other relevant information. Bettavaro may decide an appropriate resolution under marketplace rules, including refund, partial refund, cancellation, balance adjustment, or other corrective action.',
                ],
                [
                    'id' => 'fees_balance_payout_rules',
                    'title' => 'Fees, Balance, and Payout Rules',
                    'body' => 'Sellers accept the platform fee, refund fee, balance hold, payout delay, fraud review, and other marketplace balance rules that apply to seller activity. Bettavaro may hold, delay, offset, or adjust payouts during a dispute, refund review, fraud review, chargeback, policy violation, account verification, or unsafe selling investigation. Payout timing may depend on order completion, buyer protection periods, payment processor rules, and marketplace risk controls.',
                ],
                [
                    'id' => 'prohibited_conduct_anti_fraud',
                    'title' => 'Prohibited Conduct and Anti-Fraud',
                    'body' => 'Sellers must not commit fraud, manipulate auctions, create fake orders, use fake buyer accounts, copy another seller’s media, misrepresent fish origin, avoid marketplace fees, request unsafe payment methods, sell prohibited animals, or make false claims about awards, champion status, rarity, strain, genetics, health, or availability. Bettavaro may review transactions, listings, messages, balances, and account activity to protect buyers, sellers, and the marketplace.',
                ],
                [
                    'id' => 'account_review_suspension_removal',
                    'title' => 'Account Review, Suspension, or Removal',
                    'body' => 'Bettavaro may review, limit, suspend, or remove a seller account for fraud, repeated complaints, abusive behavior, unsafe selling practices, repeated cancellations, poor fulfillment, misleading listings, payment risk, dispute abuse, or other marketplace policy violations. Bettavaro may also remove listings, hold payouts, restrict features, or require additional verification when needed for marketplace safety.',
                ],
                [
                    'id' => 'agreement_version_acceptance',
                    'title' => 'Agreement and Version Acceptance',
                    'body' => 'By applying to sell or continuing to sell on Bettavaro, the seller confirms that they have read, understood, and agree to these Seller Terms and Marketplace Rules. Acceptance is recorded using the current terms version. Bettavaro may update these terms when marketplace rules, payout rules, safety requirements, or legal requirements change, and sellers may be required to accept a newer version before selling continues.',
                ],
            ],
            'required_acceptance' => true,
            'acceptance_field' => 'agree_terms',
            'acceptance_statement' => 'I have read and agree to the Bettavaro Seller Terms and Marketplace Rules.',
            'next_step' => [
                'endpoint' => '/api/mobile/v1/seller_apply.php',
                'method' => 'POST',
                'required_fields' => ['agree_terms', 'terms_version'],
            ],
        ],
    ],
    'meta' => [
        'api_version' => 'mobile-v1',
        'generated_at' => gmdate('c'),
    ],
], JSON_UNESCAPED_SLASHES);
