<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ZieglerPdfAssistant extends PdfClient
{
    public static function validateFormat (array $lines) {
        return $lines[0] == "ZIEGLER UK LTD"
            && $lines[1] == "LONDON GATEWAY LOGISTICS PARK"
            && $lines[2] == "NORTH 4, NORTH SEA CROSSING"
            && $lines[3] == "STANFORD LE HOPE"
            && Str::startsWith($lines[11], "Ziegler Ref");
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        /*Customer Information Start*/
        $customer = [
            'side' => 'none',
            'details' => [
                'company' => 'ZIEGLER UK LTD',
                'street_address' => "London Gateway Logistics Park, North 4, North Sea Crossing",
                'city' => 'STANFORD LE HOPE',
                'postal_code' => 'SS17 9FJ',
                'country' => 'GB'
            ],
        ];
        /*Customer Information End*/

        /*Loading Locations Start*/
        $loading_list  = array_find_key($lines, fn($l) => $l == "Collection");
        $delivery_list = array_find_key($lines, fn($l) => $l === "Clearance" || $l === "Delivery");

        $loading_locations = $this->extractLocations(
            array_slice($lines, $loading_list, $delivery_list - 1 - $loading_list)
        );
        /*Loading Locations End*/

        /*Delivery Locations Start*/
        $delivery_list  = array_find_key($lines, fn($l) => $l == "Clearance" || $l === "Delivery");
        $endline_list = array_find_key($lines, fn($l) => $l === "- Payment will only be made once a statement is received by our accounts department");

        $destination_locations = $this->extractLocations(
            array_slice($lines, $delivery_list, $endline_list - 1 - $delivery_list)
        );
        /*Delivery Locations End*/

        /*Cargo List Start*/
        $loading_list  = array_find_key($lines, fn($l) => $l == "Collection");
        $delivery_list = array_find_key($lines, fn($l) => $l === "Clearance" || $l === "Delivery");

        $cargos = $this->extractCargo(
            array_slice($lines, $loading_list, $delivery_list - 1 - $loading_list)
        );
        /*Cargo List End*/

        /*Order Reference Start*/
        $order_ref = array_find_key($lines, fn($l) => $l == "Ziegler Ref");
        $order_reference = trim($lines[$order_ref+2]);
        /*Order Reference End*/

        /*Freight Price Start*/
        $freight_price = array_find_key($lines, fn($l) => $l == "Rate");
        $freight_price = $lines[$freight_price+2];
        $freight_price = preg_replace('/[^\d,\.]/', '', $freight_price);
        $freight_price = uncomma($freight_price)*1000; //the helper function thinks the , in freight price separates decimal point only but here , separates thousand
        /*Freight Price End*/
        
        /*Freight Currency Start*/
        $freight_currency = "EUR";
        /*Freight Currency End*/
        
        /*Attachments Start*/
        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];
        /*Attachments End*/

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'freight_price',
            'freight_currency',
        );

        $this->createOrder($data);

    }

    public function extractLocations(array $lines) {
        $collections    = [];
        $current_block  = [];
        $collecting     = false;
        $lastIndex      = array_key_last($lines);

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') continue;

            if ((strcasecmp($line, 'Collection') === 0) || (strcasecmp($line, 'Clearance') === 0) || (strcasecmp($line, 'Delivery') === 0)) {
                // Save previous block if we were collecting
                if ($collecting && !empty($current_block)) {
                    $collections[] = [
                                        'company_address' => $this->extractCompanyInfo($current_block),
                                        'time'            => $this->extractTime($current_block)
                                     ];
                }
                $current_block = [];
                $collecting = true;
                continue;
            }

            if ($collecting && $index > $lastIndex) {
                $collecting = false;
                if (!empty($current_block)) {
                    $collections[] = [
                                        'company_address' => $this->extractCompanyInfo($current_block),
                                        'time'            => $this->extractTime($current_block)
                                     ];
                }
                $current_block = [];
                continue;
            }

            if ($collecting) {
                $current_block[] = $line;
            }
        }

        if ($collecting && !empty($current_block)) {
            $collections[] = [
                                'company_address' => $this->extractCompanyInfo($current_block),
                                'time'            => $this->extractTime($current_block)
                             ];
        }

        return $collections;
    }

    public function extractCompanyInfo(array $array) {
        $companyName = trim($array[0]);
        $getPostalCodeandCity = [
            'city'        => '',
            'postal_code' => ''
        ];

        $streetLines = [];
        foreach ($array as $i => $line) {
            $line = trim($line);

            if ($i === 0 || $line === '') continue;

            if ($this->isPostalCodeLine($line)) {
                $getPostalCodeandCity = $this->extractCityandPostalCode($line);
                continue; // skip adding this line to street
            }

            // Skip known keywords or patterns
            if (preg_match('/^\s*(REF|PICK UP|PALLETS|BOOKED|WH|TIME|DELIVERY|UNITS|C\/O)\b/i', $line)) continue;
            if (preg_match('/\d{1,2}\/\d{1,2}\/\d{4}/', $line)) continue; // dates
            if (preg_match('/^\d{3,4}-\d{1,4}(am|pm)?$/i', $line)) continue;
            if (preg_match('/^\d{1,2}:\d{2}\s*Time To:\s*\d{1,2}:\d{2}$/i', $line)) continue;
            if (preg_match('/^\d+ pallets?/i', $line)) continue;      // pallet counts

            // Anything left is probably street address
            $streetLines[] = $line;
        }

        return [
            'company'        => $companyName,
            'street_address' => implode(', ', $streetLines),
            'city'           => $getPostalCodeandCity['city'],
            'postal_code'    => $getPostalCodeandCity['postal_code']
        ];
    }

    function isPostalCodeLine(string $line): bool {
        $line = trim($line);
        return preg_match('/(?:(?:FR|DE|BE|NL|ES|IT|LU|CH)?-?\s*(\d{5})|([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}))\s*(.*)|(.+?)\s+(?:(?:FR|DE|BE|NL|ES|IT|LU|CH)?-?\s*(\d{4,5})|([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}))/i', $line);

    }

    public function extractCityandPostalCode(string $line)
    {
        $address  = trim(str_replace(',', '', $line));
        $city     = '';
        $postcode = '';

        if (preg_match('/\b(?:(FR|DE|BE|NL|ES|IT|LU|CH)-?)?\s*(\d{4,5})\b|\b([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\b/i', $line, $matches)) {
            if (!empty($matches[2])) {
                $postcode = $matches[2];
                $city = trim(str_replace($matches[0], '', $line));
            } elseif (!empty($matches[3])) {
                $postcode = strtoupper($matches[3]);
                $city = trim(str_replace($matches[3], '', $line));
            }

            $city = preg_replace('/\b(FR|DE|BE|NL|ES|IT|LU|CH|GB|UK)\b[-\s]*/i', '', $city);
            $city = rtrim($city, ',');  
            $city = trim($city);
        }

        return [
            'city'        => trim($city),
            'postal_code' => $postcode
        ];
    }

    public function extractTime(array $lines) {
        $date = "";
        $startTime = "00:00";
        $endTime = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (!$line) continue;

            if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $line, $m)) {
                $date = $m[1];
            }

            if (!preg_match('/^(?:BOOKED-)?\s*(\d{1,2}(?::\d{2})?|\d{3,4})(?:\s*(am|pm))?\s*(?:-|Time To:)?\s*(\d{1,2}(?::\d{2})?|\d{1,2}(?:\d{2})?)?(?:\s*(am|pm))?$/i', $line, $m)) {
                continue;
            }
            
            $startRaw = trim($m[1] . ' ' . ($m[2] ?? ''));
            $endRaw   = isset($m[3]) && $m[3] ? trim($m[3] . ' ' . ($m[4] ?? '')) : null;

            $startTime = $this->normalizeTime($startRaw);
            $endTime = $endRaw ? $this->normalizeTime($endRaw) : null;
        }

        if (!$date) {
            $date = date('d/m/Y');
        }

        $startDate = Carbon::createFromFormat('d/m/Y H:i', "$date $startTime")->toIsoString();
        $endDate = $endTime ? Carbon::createFromFormat('d/m/Y H:i', "$date $endTime")->toIsoString() : null;

        $result = ['datetime_from' => $startDate];

        if ($endDate && $startDate !== $endDate) {
            $result['datetime_to'] = $endDate;
        }

        return $result;
    }

    public function normalizeTime(string $time) {
        $time = strtolower(trim($time));
        if (!$time) return "00:00";

        if (preg_match('/^(\d{1,2})(?::?(\d{2}))?\s*(am|pm)?$/i', $time, $m)) {
            $hour = (int)$m[1];
            $minute = isset($m[2]) ? (int)$m[2] : 0;
            $ampm = $m[3] ?? null;

            if ($ampm) {
                if ($ampm === 'pm' && $hour < 12) $hour += 12;
                if ($ampm === 'am' && $hour === 12) $hour = 0;
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        if (preg_match('/^(\d{1,2})(\d{2})$/', $time, $m)) {
            return sprintf('%02d:%02d', $m[1], $m[2]);
        }

        if (preg_match('/^\d{1,2}$/', $time)) {
            return sprintf('%02d:00', $time);
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return $time;
        }

        return "00:00";
    }

    public function extractCargo(array $array) {
        $data = [];
        foreach($array as $line) {
            $line = trim($line);
            if (preg_match('/\b\d+\s+pallets?\b/i', $line, $matches)) {
                $palletCount = (int)$matches[0]; // extract number

                $data[] = [
                    'title'         => '',
                    'package_count' => $palletCount,
                    'package_type'  => 'pallet'
                ];
            }
        }
        return $data;
    }

}