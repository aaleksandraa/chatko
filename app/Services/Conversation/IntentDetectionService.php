<?php

namespace App\Services\Conversation;

class IntentDetectionService
{
    public function detect(string $message): string
    {
        $text = strtolower($message);

        if (preg_match('/\b(cijena|kosta|price|koliko)\b/', $text)) {
            return 'price_question';
        }

        if (preg_match('/\b(dostava|isporuka|shipping)\b/', $text)) {
            return 'shipping_question';
        }

        if (preg_match('/\b(povrat|vratit|returns?)\b/', $text)) {
            return 'returns_question';
        }

        if (preg_match('/\b(uporedi|poredi|razlika|compare)\b/', $text)) {
            return 'product_comparison';
        }

        if (preg_match('/\b(kupi|kupim|naruci|checkout|korpa)\b/', $text)) {
            return 'checkout_ready';
        }

        if (preg_match('/\b(hej|zdravo|cao|hello|hi)\b/', $text)) {
            return 'greeting';
        }

        if (preg_match('/\b(telefon|email|kontaktiraj|agent|covjek)\b/', $text)) {
            return 'human_help_request';
        }

        return 'product_recommendation';
    }
}