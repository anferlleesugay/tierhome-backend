<?php

namespace App\Http\Controllers;

use App\Models\ChatbotLog;
use App\Models\PropertyListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    /**
     * Keyword-based fallback responses.
     * Used only if the Gemini API call fails or times out.
     */
    protected $fallbackFaqs = [
        'register'       => "To register, click 'Sign Up' and choose whether you're a Tenant or Property Owner. You'll then need to verify your identity with any valid ID and a selfie before you get full access.",
        'sign up'        => "To register, click 'Sign Up' and choose whether you're a Tenant or Property Owner. You'll then need to verify your identity with any valid ID and a selfie before you get full access.",
        'verify'         => "Identity verification requires any valid ID and a real-time selfie. Our system checks that they match before granting full access to the platform.",
        'verification'   => "Identity verification requires any valid ID and a real-time selfie. Our system checks that they match before granting full access to the platform.",
        'ranking'        => "The affordability ranking evaluates your income, expenses, and savings to categorize listings into Highly Affordable, Moderately Affordable, and Financially Risky tiers.",
        'afford'         => "The affordability ranking evaluates your income, expenses, and savings to categorize listings into Highly Affordable, Moderately Affordable, and Financially Risky tiers.",
        'reserve'        => "To reserve a property, open a listing and click 'Reserve'. You'll confirm you've spoken with the Property Owner, then submit your move-in date and details for the Property Owner to review.",
        'reservation'    => "To reserve a property, open a listing and click 'Reserve'. You'll confirm you've spoken with the Property Owner, then submit your move-in date and details for the Property Owner to review.",
        'cancel'         => "You can cancel a pending or approved reservation anytime from 'My Reservations'. You'll be asked for an optional reason before it's cancelled.",
        'password'       => "Click 'Forgot Password' on the login page and check your email for a reset link.",
        'listing'        => "Property Owners can add listings from their Listings page, including photos, pricing, amenities, and house rules & policies. Listings are reviewed by an admin before going live.",
        'terms'          => "Each listing may have its own House Rules & Policies set by the Property Owner, such as deposit requirements or house rules. You'll see these when viewing a listing and must agree to them before reserving.",
        'amenit'         => "Amenities are shown on each listing page. Property Owners can also request new amenity types if the one they need isn't listed.",
    ];

    /**
     * Loose keyword signal that the user is asking to find/browse a rental,
     * not just asking a general question about the platform. Deliberately
     * broad — a false positive here just means we attempt a listing lookup
     * that may come back empty, which is handled gracefully either way.
     */
    protected $searchIntentPhrases = [
        'find me', 'find a', 'looking for', 'search for', 'show me',
        'any listing', 'any house', 'any apartment', 'any condo',
        'available', 'home in', 'house in', 'apartment in', 'place in',
        'rent in', 'condo in', 'room in', 'unit in', 'property in',
        'looking to rent', 'want to rent',
    ];

    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $userMessage = $validated['message'];
        $user        = auth('api')->user();
        $userId      = $user?->id;

        // Listing search capability: guests (null role) and tenants only.
        // Property Owners and Admins get the normal FAQ-only chatbot —
        // browsing rentals for themselves isn't relevant to those roles.
        $listingContext = null;
        if (in_array($user?->role, [null, 'tenant'], true)) {
            $intent = $this->detectListingSearchIntent($userMessage);
            if ($intent) {
                // ✅ FIX: detectListingSearchIntent() returns ['area' => ...],
                // this previously read $intent['barangay'] — a leftover key
                // name from before the area/barangay+address rework, which
                // meant the detected area was always silently dropped as
                // null here, regardless of what the user actually typed.
                $listingContext = $this->fetchListingsForChat($intent['area']);
            }
        }

        $response = $this->askGemini($userMessage, $listingContext);

        if (!$response) {
            $response = $this->fallbackAnswer($userMessage);
        }

        try {
            ChatbotLog::create([
                'user_id'  => $userId,
                'question' => $userMessage,
                'response' => $response,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log chatbot exchange: ' . $e->getMessage());
        }

        // Structured listing data goes alongside the text response so the
        // frontend can render an actual clickable link/card per listing,
        // rather than the user having to tap a plain-text path mentioned
        // inside the message.
        return response()->json([
            'success' => true,
            'data'    => [
                'response' => $response,
                'listings' => $listingContext ?? [],
            ],
        ]);
    }

    /**
     * Complete official list of Angeles City barangays. Used to recognize
     * area names in a user's message — deliberately NOT limited to whatever
     * happens to be an exact value in the barangay column right now, since
     * some listings store a subdivision name there instead (e.g. "Villasol
     * Subdivision" for a property that's actually in Balibago, per its own
     * address field). Matching against the full official list, then
     * searching both barangay AND address for that name, catches these
     * cases correctly instead of reporting a false "no listings found."
     */
    protected $angelesCityBarangays = [
        'Agapito del Rosario', 'Amsic', 'Anunas', 'Asturias', 'Balibago',
        'Capaya', 'Claro M. Recto', 'Cuayan', 'Cutcut', 'Cutud',
        'Lourdes Norte', 'Lourdes Sur', 'Lourdes Sur East', 'Malabanias',
        'Margot', 'Mining', 'Ninoy Aquino', 'Pampang', 'Pandan',
        'Pulung Cacutud', 'Pulung Maragul', 'Salapungan', 'San Jose',
        'San Nicolas', 'Santa Teresita', 'Santa Trinidad', 'Santo Cristo',
        'Santo Domingo', 'Santo Rosario', 'Sapalibutad', 'Sapangbato',
        'Tabun', 'Virgen Delos Remedios',
    ];

    /**
     * Checks whether the message reads like a listing search request, and if
     * so, tries to recognize a real Angeles City barangay name in it. This
     * is matched against the OFFICIAL barangay list, not against whatever
     * values currently sit in the barangay column — see fetchListingsForChat()
     * for why that distinction matters.
     *
     * Returns null if this doesn't look like a search at all. Returns
     * ['area' => null] for a general "find me a home" with no specific area
     * named. Returns ['area' => 'Balibago'] (etc.) when a real barangay name
     * is recognized in the message, regardless of whether any listing's
     * barangay column happens to say that exact value.
     */
    protected function detectListingSearchIntent(string $message): ?array
    {
        $lower = strtolower($message);

        $hasSearchIntent = false;
        foreach ($this->searchIntentPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                $hasSearchIntent = true;
                break;
            }
        }
        if (!$hasSearchIntent) {
            return null;
        }

        $matchedArea = null;
        foreach ($this->angelesCityBarangays as $barangay) {
            if (str_contains($lower, strtolower($barangay))) {
                $matchedArea = $barangay;
                break;
            }
        }

        return ['area' => $matchedArea];
    }

    /**
     * Fetches real, currently-approved listings for the chatbot to
     * recommend. Deliberately small (3 max) — this is a conversational
     * suggestion, not a full search results page.
     *
     * A listing is recommendable when it's been admin-approved
     * (is_verified = 1) AND is currently open for reservations
     * (status = 'available') — NOT a single 'approved' status value,
     * which doesn't exist in this schema.
     *
     * Searches BOTH the barangay column and the address field for the
     * requested area — some listings store a subdivision name in barangay
     * rather than the official barangay (e.g. "Villasol Subdivision"
     * instead of "Balibago"), even though the actual barangay is named
     * correctly in the full address. Checking address too means a search
     * for "Balibago" still finds that listing instead of reporting a false
     * "no listings found."
     */
    protected function fetchListingsForChat(?string $area): array
    {
        $query = PropertyListing::where('is_verified', 1)
            ->where('status', 'available');

        if ($area) {
            $query->where(function ($q) use ($area) {
                $q->where('barangay', 'like', "%{$area}%")
                  ->orWhere('address', 'like', "%{$area}%");
            });
        }

        return $query->orderBy('created_at', 'desc')
            ->limit(3)
            ->get(['id', 'title', 'price', 'barangay', 'property_type'])
            ->toArray();
    }

    /**
     * Call Gemini API. Returns null on any failure so the caller can fall back.
     *
     * $listingContext, when not null, means a listing search was attempted:
     *   - a non-empty array = real listings to recommend by name/price
     *   - an empty array = search was attempted but nothing matched, so
     *     Gemini is told explicitly not to invent a result
     */
    protected function askGemini(string $userMessage, ?array $listingContext = null): ?string
    {
        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-2.5-flash-lite');

        if (!$apiKey) {
            return null;
        }

        $systemPrompt = "You are the TierHome support chatbot. TierHome is a web and mobile housing "
            . "decision support platform for Angeles City, Philippines. It helps tenants find rental "
            . "properties ranked by affordability (based on income, expenses, and savings), requires "
            . "identity verification (valid ID + selfie) for all users, and lets Property Owners list "
            . "properties with map-based location, amenities, and house rules & policies. Tenants can "
            . "reserve listings, message Property Owners, and save favorites. Keep answers short (2-4 "
            . "sentences), friendly, and specific to TierHome. If asked something unrelated to "
            . "TierHome or housing in Angeles City, politely say you can only help with TierHome "
            . "platform questions and suggest contacting the system administrator for anything else. "
            . "IMPORTANT: TierHome accepts ANY valid photo ID for verification, not just "
            . "government-issued ones. Never describe the ID requirement as \"government-issued,\" "
            . "\"government ID,\" or \"valid government ID\" -- always say \"valid ID\" or \"any valid ID\" instead.";

        if ($listingContext !== null) {
            if (count($listingContext) > 0) {
                $listingsText = collect($listingContext)->map(function ($l) {
                    return "- \"{$l['title']}\" in {$l['barangay']}, ₱" . number_format($l['price'])
                        . "/month ({$l['property_type']}) — view it at /listing/{$l['id']}";
                })->implode("\n");

                $systemPrompt .= "\n\nThe user is looking for a rental. Here are REAL, currently "
                    . "approved listings matching their request:\n{$listingsText}\n\nRecommend these "
                    . "specific listings naturally in your response, mentioning the name, price, and "
                    . "that they can view more details at the listed page path. Do not invent, "
                    . "describe, or mention any listing other than the ones given above.";
            } else {
                $systemPrompt .= "\n\nThe user is looking for a rental in a specific area, but there "
                    . "are currently no approved listings matching that area. Say so politely, and "
                    . "suggest they browse all listings or try a different barangay. Do not invent a "
                    . "listing that doesn't exist.";
            }
        }

        try {
            $res = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'system_instruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $userMessage]]],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.4,
                        'maxOutputTokens' => 300,
                    ],
                ]
            );

            if (!$res->successful()) {
                Log::warning('Gemini API error: ' . $res->status() . ' ' . $res->body());
                return null;
            }

            $text = data_get($res->json(), 'candidates.0.content.parts.0.text');

            return $text ? trim($text) : null;

        } catch (\Exception $e) {
            Log::warning('Gemini API exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Simple keyword-matching fallback, used only if Gemini is unavailable.
     * Deliberately does NOT attempt listing search — the fallback path has
     * no way to safely retrieve/verify real data without Gemini to phrase
     * it, so it sticks to FAQ answers only, same as before this feature.
     */
    protected function fallbackAnswer(string $message): string
    {
        $lower = strtolower($message);

        foreach ($this->fallbackFaqs as $keyword => $answer) {
            if (str_contains($lower, $keyword)) {
                return $answer;
            }
        }

        return "I'm having trouble reaching my full assistant right now, but I can help with basic "
            . "questions about registering, identity verification, affordability ranking, listings, "
            . "and reservations. You can also contact the system administrator for anything else.";
    }
}