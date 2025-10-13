<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    public static function validateFormat (array $lines) {
        return Str::startsWith($lines[0], "Date/Time :")
               && array_find_key($lines, fn($l) => $l === "VAT NUM: GB712061386")
               && array_find_key($lines, fn($l) => $l === "invoice.ts@transalliance.eu");
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        /*Customer Information Start*/
        $contact_info = array_find_key($lines, fn($l) => Str::startsWith($l, 'Contact: '));
        $contact_person = explode(': ', $lines[$contact_info], 2)[1];
        $contact_person_email = array_find_key($lines, fn($l) => Str::startsWith($l, 'E-mail :'));
        $contact_person_email = trim($lines[$contact_person_email + 1]);
        
        $customer = [
            'side' => 'sender',
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
        $loading_list = array_find_key($lines, fn($l) => Str::startsWith($l, 'Loading'));

        $unloading_list = array_find_key($lines, fn($l) => Str::startsWith($l, 'Instructions'));

        $loading_locations =  $this->extractLocations(
            array_slice($lines, $loading_list+3, $unloading_list - 3 - $loading_list)
        );
        /*Loading Information End*/

        /*Destination Information Start*/
        $unloading_list = array_find_key($lines, fn($l) => Str::startsWith($l, 'Delivery'));

        $endline = array_find_key($lines, fn($l) => Str::startsWith($l, "Observations"));

        $destination_locations =  $this->extractLocations(
            array_slice($lines, $unloading_list+3, $endline - 1 - $unloading_list)
        );
        
        /*Destination Information End*/

        /*Cargo List Start*/
        $loading_list  = array_find_key($lines, fn($l) => $l == "Loading");

        $delivery_list = array_find_key($lines, fn($l) => Str::startsWith($l, 'Instructions'));

        $cargos = $this->extractCargo(
            array_slice($lines, $loading_list+3, $delivery_list - 3 - $loading_list)
        );
        /*Cargo List End*/

        /*Order Reference Start*/
        $loading_list  = array_find_key($lines, fn($l) => $l == "Loading");

        $delivery_list = array_find_key($lines, fn($l) => Str::startsWith($l, 'Instructions'));

        $order_reference = $this->extractOrderRef(
            array_slice($lines, $loading_list+3, $delivery_list - 3 - $loading_list)
        );
        /*Order Reference End*/

        /*Freight Price Start*/
        $freight_price = array_find_key($lines, fn($l) => $l == "SHIPPING PRICE");
        $freight_price = $lines[$freight_price+1];
        $freight_price = preg_replace('/[^\d,\.]/', '', $freight_price);
        $freight_price = uncomma($freight_price);
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

    public function extractLocations(array $array) {
        $companyInfo = $this->extractCompanyInfo($array);
        $timeInfo    = $this->extractTime($array);

        $data[] = [
            'company_address' => $companyInfo,
            'time'            => $timeInfo
        ];

        return $data;
    }

    public function extractCompanyInfo(array $array) {
        $companyName = array_find_key($array, fn($l) => Str::startsWith($l, 'ON:'));
        $companyName = $array[$companyName+2];

        $getPostalCodeandCity = [
            'city'        => '',
            'postal_code' => ''
        ];

        $streetLines = [];
        foreach ($array as $i => $line) {
            $line = trim($line);

            if ($i === 0 || $line === '' || $line===$companyName) continue;

            if (preg_match('/[,\:]/', $line)) continue;
            if ($this->isPostalCodeLine($line)) {
                $getPostalCodeandCity = $this->extractCityandPostalCode($line);
                continue; // skip adding this line to street
            }

            // Skip known keywords or patterns
            $junkPattern = '/(?:^|[\s,.-])(?:' .
                                'ON\b' .
                                '|Tel\b' .
                                '|VIREMENT 60 J' .
                                '|PAPER ROLLS' .
                                '|PACKAGING' .
                                '|Instructions' .
                                '|LL DRIVERS TO ASK FOR THE' .
                                '|BON D\'ECHANGE\' FROM ALL DELIVERY SITES I' .
                            ')(?=$|[\s,.-])/iu';

            if (preg_match($junkPattern, $line)) {
                continue; // skip entire line if any token is present
            }

            if (preg_match('/^\d+$/', $line)) continue;
            if (preg_match('/[-]/', $line)) continue;
            if (preg_match('/\d{1,2}\/\d{1,2}\/\d{2}/', $line)) continue; // dates

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

        return preg_match('/^\s*-?\s*(?:[A-Z]{2}-)?(\d{4,5}|[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\s+[A-Z][A-Z0-9\s\-]+$/i', $line);
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
            $city = ltrim($city, "- ");
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

            if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{2})/', $line, $m)) {
                $date = $m[1];
            }

            if (!preg_match('/\b(\d{1,2})h(\d{2})\s-\s(\d{1,2})h(\d{2})\b/i', $line, $m)) {
                continue;
            }
            
            $startRaw = trim($m[1] . ':' . ($m[2] ?? ''));
            $endRaw   = isset($m[3]) && $m[3] ? trim($m[3] . ':' . ($m[4] ?? '')) : null;

            $startTime = $this->normalizeTime($startRaw);
            $endTime = $endRaw ? $this->normalizeTime($endRaw) : null;
        }

        if (!$date) {
            $date = date('d/m/y');
        }

        $startDate = Carbon::createFromFormat('d/m/y H:i', "$date $startTime")->toIsoString();
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
        $numericLines = [];
        $title = 0;
        $number = 0;
        
        foreach($array as $index => $line) {
            $line = trim($line);
            if (preg_match('/^\d{1,5}(?:,\d{3})*(?:\.\d+)?$/', $line)) {
                $numericLines[] = uncomma($line);
                $title = $index+2;
            }

            if (preg_match('/\bOT\s*:/i', $line)) {
                $number = $index+2;
            }
        }

        $data[] = [
                    'title'         => $array[$title],
                    'package_type'  => 'other',
                    'number'        => $array[$number],
                    'ldm'           => $numericLines[0] * 1000,
                    'weight'        => $numericLines[1]
        ];

        return $data;
    }

    public function extractOrderRef(array $array) {
        $orderRef = 0;
        foreach($array as $index => $line) {
            $line = trim($line);

            if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{2})/', $line, $m)) {
               $orderRef = $index+1;
            }
        }

        return rtrim($array[$orderRef], "- ");
    }
}