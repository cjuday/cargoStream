<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    public static function validateFormat (array $lines) {
        return $lines[14] == "TRANSALLIANCE TS LTD"
            && $lines[4]  == "CHARTERING CONFIRMATION"
            && $lines[40] == "SHIPPING PRICE";
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        $customer = [
            'sides' => 'sender',
            'details' => [
                'company' => 'Transalliance TS Ltd',
                'street_address' => 'Suite 8/9 Faraday Court, Centrum One Hundred',
                'city' => 'Burton Upon Trent',
                'postal_code' => 'DE14 2WX',
                'vat_code' => 'GB712061386',
                'company_code' => '643408312',
            ],
        ];

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'transport_numbers',
            'freight_price',
            'freight_currency',
        );

        $this->createOrder($data);
    }
}