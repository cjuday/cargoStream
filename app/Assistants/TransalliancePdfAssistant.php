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
            && $lines[40] == "SHIPPING PRICE"
            && Str::startsWith($lines[29], "Contact: ");
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        /*Customer Information Start*/
        $contact_info = array_find_key($lines, fn($l) => Str::startsWith($l, 'Contact: '));
        $contact_person = explode(': ', $lines[$contact_info], 2)[1];
        $contact_person_email = trim($lines[34]);
        
        $customer = [
            'sides' => 'sender',
            'details' => [
                'company'           => 'Transalliance TS Ltd',
                'vat_code'          => 'GB712061386',
                'email'             => $contact_person_email,
                'contact_person'    => $contact_person,
                'street_address'    => 'Suite 8/9 Faraday Court, Centrum One Hundred',
                'city'              => 'Burton Upon Trent',
                'company_code'      => '643408312',
                'postal_code'       => 'DE14 2WX'
            ],
        ];
        /*Customer Information End*/

        /*Loading Information Start*/
        $loadingCompanyInfo = $this->parseCompanyInformation($lines[63], $lines[64], $lines[65]);

        $loadingDateTime    = $this->parseDateTime($lines[58], $lines[73]);

        $loading_locations  = [
            'company_address' => $loadingCompanyInfo,
            'time'            => $loadingDateTime
        ];
        /*Loading Information End*/

        /*Destination Information Start*/
        $destinationCompanyInfo = $this->parseCompanyInformation($lines[107], $lines[108], $lines[109]);

        $destinationDateTime    = $this->parseDateTime($lines[102], $lines[114]);

        $destination_locations  = [
            'company_address' => $destinationCompanyInfo,
            'time'            => $destinationDateTime
        ];
        /*Destination Information End*/

        dd($destination_locations);

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

    public function parseDateTime($line1, $line2) {
        $date       = trim($line1);
        $time       = trim($line2);

        $timeParts  = explode(' - ', $time);
        $startTime  = str_replace('h', ':', trim($timeParts[0]));
        $endTime    = isset($timeParts[1]) ? str_replace('h', ':', trim($timeParts[1])) : $startTime;

        //tranforming date and time to ISO string
        $dateFrom   = Carbon::createFromFormat('d/m/y H:i', "$date $startTime")->toIsoString();
        $dateTo     = Carbon::createFromFormat('d/m/y H:i', "$date $endTime")->toIsoString();

        return [
            'datetime_from' => $dateFrom, 
            'datetime_to' => $dateTo
        ];
    }

    public function parseCompanyInformation($line1, $line2, $line3) {
        $loadingCompanyName            = trim($line1);
        $loadingCompanyStreetAddress   = trim($line2);
        
        $loadingCompanyPostalCodeWithCountry    = null;
        $loadingCompanyPostalCodeWithoutCountry = null;
        $loadingCompanyCity                     = null;
        $loadingCompanyCountry                  = null;

        // Normalize line 3: remove invisible chars, normalize dash types
        $line3 = preg_replace('/\p{C}+/u', '', $line3); // remove control/non-printables
        $line3 = str_replace(["–", "—", "−", "-", "‒"], "-", $line3); // normalize all dash types to '-'
        $line3 = preg_replace('/\s+/', ' ', trim($line3));

        if (preg_match('/^(?:([A-Z]{1,3})-)?([\w\s\-]{3,15})\s+(.+)$/i', $line3, $m)) {
            $loadingCompanyCountry = strtoupper($m[1] ?? null);
            $loadingCompanyPostalCodeWithoutCountry = trim($m[2]);
            $loadingCompanyCity = trim($m[3]); 
        }

        if(empty($loadingCompanyCountry)) {
            $possibleCountry            =  explode(" ", $line1);
            $possibleCountry            =  end($possibleCountry);
            $possibleCountry            =  ucfirst(strtolower($possibleCountry));
            $loadingCompanyCountry      =  GeonamesCountry::getIso($possibleCountry);
        }

        if(!$loadingCompanyCountry) {
            throw new \Exception("Invalid Translliance PDF: No country found!");
        }

        return [
            'company'           => $loadingCompanyName,
            'street_address'    => $loadingCompanyStreetAddress,
            'city'              => $loadingCompanyCity,
            'country'           => $loadingCompanyCountry,
            'postal_code'       => $loadingCompanyPostalCodeWithoutCountry
        ];
    }
}