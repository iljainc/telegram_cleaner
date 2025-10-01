<?php

namespace App\Services;

use App\Models\Location;
use Idpromogroup\LaravelOpenAIAssistants\Facades\OpenAIAssistants;

class LocationDetectionService
{
    /**
     * Extract and sync locations for a model using AI
     */
    public static function processModelLocations($model, string $text, string $aiContext, bool $force = false): bool
    {
        // Skip if model already has locations (unless forced)
        if (!$force && $model->locations()->count() > 0) {
            return false;
        }
        
        $assistantId = config('app.location_assistant_id');
        
        try {
            $rawAnswer = OpenAIAssistants::assistantNoThread(
                $assistantId,
                $text,
                $aiContext,
                0,
                0
            );

            // Decode JSON (handle optional ```json fences)
            $decoded = json_decode($rawAnswer, true);
            $locations = is_array($decoded['locations'] ?? null) ? $decoded['locations'] : [];

            $locationIds = [];
            foreach ($locations as $loc) {
                $country = $loc['country'] ?? null;
                $city    = $loc['city'] ?? null;
                $region  = $loc['region'] ?? null;
                
                // Normalize null values - convert string "null" to actual null
                if ($city === 'null' || $city === 'NULL' || empty($city)) $city = null;
                if ($region === 'null' || $region === 'NULL' || empty($region)) $region = null;
                if ($country === 'null' || $country === 'NULL' || empty($country)) $country = null;

                if ($country === 'WW') {
                    $location = Location::findLocation(null, null, 'WW');
                    $locationIds[] = $location->id;
                } else if (!empty($country)) {
                    $location = Location::findLocation($city, $region, strtoupper($country));
                    if ($location) {
                        $locationIds[] = $location->id;
                    }
                }
            }

            $locationIds = array_values(array_unique(array_filter($locationIds)));
            
            if (!empty($locationIds)) {
                // Synchronize locations - remove old, keep existing, add new
                $model->locations()->sync($locationIds);
                
                // Reload locations for display
                $model->load('locations');
                return true;
            }
            
        } catch (\Exception $e) {
            // Log error but continue
            \Log::error("Location detection failed for {$aiContext}", [
                'model_id' => $model->id,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }
}
