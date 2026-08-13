<?php

namespace App\Http\Controllers;

use App\Models\ChatbotLog;
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
        'register'       => "To register, click 'Sign Up' and choose whether you're a Tenant or Property Owner. You'll then need to verify your identity with a government ID and a selfie before you get full access.",
        'sign up'        => "To register, click 'Sign Up' and choose whether you're a Tenant or Property Owner. You'll then need to verify your identity with a government ID and a selfie before you get full access.",
        'verify'         => "Identity verification requires a valid Philippine government-issued ID and a real-time selfie. Our system checks that they match before granting full access to the platform.",
        'verification'   => "Identity verification requires a valid Philippine government-issued ID and a real-time selfie. Our system checks that they match before granting full access to the platform.",
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

    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $userMessage = $validated['message'];
        $userId = auth('api')->id();

        $response = $this->askGemini($userMessage);

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

        return response()->json([
            'success' => true,
            'data'    => ['response' => $response],
        ]);
    }

    /**
     * Call Gemini API. Returns null on any failure so the caller can fall back.
     */
    protected function askGemini(string $userMessage): ?string
    {
        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-2.5-flash-lite');

        if (!$apiKey) {
            return null;
        }

        $systemPrompt = "You are the TierHome support chatbot. TierHome is a web and mobile housing "
            . "decision support platform for Angeles City, Philippines. It helps tenants find rental "
            . "properties ranked by affordability (based on income, expenses, and savings), requires "
            . "identity verification (government ID + selfie) for all users, and lets Property Owners list "
            . "properties with map-based location, amenities, and house rules & policies. Tenants can "
            . "reserve listings, message Property Owners, and save favorites. Keep answers short (2-4 "
            . "sentences), friendly, and specific to TierHome. If asked something unrelated to "
            . "TierHome or housing in Angeles City, politely say you can only help with TierHome "
            . "platform questions and suggest contacting the system administrator for anything else.";

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