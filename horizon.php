<?php
/**
 * Sky Gradient - CSS Renderer
 * PHP port of the original JavaScript/TypeScript implementation by 
 * Daniel Lazaro (https://github.com/dnlzro/horizon) 
 * who has deployed it at: https://sky.dlazaro.ca
 * Renders the current sky as a CSS gradient based on atmospheric physics
 */

/**
 * Custom exceptions for better error handling
 */
class AtmosphericCalculationException extends Exception {}
class SolarPositionException extends Exception {}
class InvalidCoordinateException extends Exception {}

/**
 * Default configuration values
 */
class SkyDefaults {
    public const DEFAULT_LATITUDE = 51.285335;
    public const DEFAULT_LONGITUDE = 9.787075;
    public const FALLBACK_GRADIENT = 'linear-gradient(to bottom, rgb(135, 206, 235) 0%, rgb(135, 206, 235) 100%)';
    public const FALLBACK_COLOR = [135, 206, 235];
    public const FALLBACK_SUN_ELEVATION = 0.0;
}

/**
 * Configuration class to hold all atmospheric and rendering parameters
 */
class SkyConfig {
    // Location
    public float $latitude;
    public float $longitude;
    
    // Physical constants
    public float $pi;
    public array $rayleighScatter;
    public float $mieScatter;
    public float $mieAbsorb;
    public array $ozoneAbsorb;
    
    // Altitude density distribution
    public float $rayleighScaleHeight;
    public float $mieScaleHeight;
    
    // Atmospheric parameters
    public float $groundRadius;
    public float $topRadius;
    public float $sunIntensity;
    
    // Rendering parameters
    public int $samples;
    public float $fovDeg;
    
    // Post-processing
    public float $exposure;
    public float $gamma;
    public float $sunsetBiasStrength;
    
    // Performance tuning
    public int $maxTransmittanceCache;
    public int $maxTrigCache;
    public bool $enableCaching;
    
    // Pre-calculated constants
    public float $fovRadians;
    public float $focalZ;
    public float $segmentLength;
    public array $phaseConstants;
    
    public function __construct(float $latitude = 51.285335, float $longitude = 9.787075) {
        // Validate coordinates
        $this->validateCoordinates($latitude, $longitude);
        
        // Location
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        
        // Physical constants
        $this->pi = M_PI;
        $this->rayleighScatter = [5.802e-6, 13.558e-6, 33.1e-6];
        $this->mieScatter = 3.996e-6;
        $this->mieAbsorb = 4.44e-6;
        $this->ozoneAbsorb = [0.65e-6, 1.881e-6, 0.085e-6];
        
        // Altitude density distribution
        $this->rayleighScaleHeight = 8e3;
        $this->mieScaleHeight = 1.2e3;
        
        // Atmospheric parameters
        $this->groundRadius = 6_360e3;
        $this->topRadius = 6_460e3;
        $this->sunIntensity = 1.0;
        
        // Rendering parameters
        $this->samples = 32;
        $this->fovDeg = 75;
        
        // Post-processing
        $this->exposure = 25.0;
        $this->gamma = 2.2;
        $this->sunsetBiasStrength = 0.1;
        
        // Performance tuning (configurable cache sizes)
        $this->maxTransmittanceCache = 1000;
        $this->maxTrigCache = 500;
        $this->enableCaching = true;
        
        // Pre-calculate expensive constants
        $this->preCalculateConstants();
    }
    
    private function validateCoordinates(float $latitude, float $longitude): void {
        if (!is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            throw new InvalidCoordinateException('Latitude must be between -90 and 90 degrees');
        }
        if (!is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new InvalidCoordinateException('Longitude must be between -180 and 180 degrees');
        }
    }
    
    private function preCalculateConstants(): void {
        $this->fovRadians = deg2rad($this->fovDeg * 0.5);
        $this->focalZ = 1.0 / tan($this->fovRadians);
        
        // Pre-calculate Mie phase function constants
        $g = 0.8;
        $this->phaseConstants = [
            'rayleighScale' => 3 / (16 * $this->pi),
            'mieScale' => 3 / (8 * $this->pi),
            'mieG' => $g,
            'mieG2' => $g * $g,
            'mieCoeff' => (1 - $g * $g),
            'mieDenom' => (2 + $g * $g)
        ];
    }
}

/**
 * Vector math utilities class
 */
class VectorMath {
    public static function clamp(float $x, float $min, float $max): float {
        return max($min, min($max, $x));
    }
    
    public static function dot(array $v1, array $v2): float {
        if (count($v1) !== 3 || count($v2) !== 3) {
            throw new InvalidArgumentException('Vectors must be 3D');
        }
        return $v1[0] * $v2[0] + $v1[1] * $v2[1] + $v1[2] * $v2[2];
    }
    
    public static function length(array $v): float {
        if (count($v) !== 3) {
            throw new InvalidArgumentException('Vector must be 3D');
        }
        return sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);
    }
    
    public static function normalize(array $v): array {
        $l = self::length($v);
        if ($l === 0.0) {
            return [0.0, 0.0, 0.0];
        }
        return [$v[0] / $l, $v[1] / $l, $v[2] / $l];
    }
    
    public static function add(array $v1, array $v2): array {
        if (count($v1) !== 3 || count($v2) !== 3) {
            throw new InvalidArgumentException('Vectors must be 3D');
        }
        return [$v1[0] + $v2[0], $v1[1] + $v2[1], $v1[2] + $v2[2]];
    }
    
    public static function scale(array $v, float $s): array {
        if (count($v) !== 3) {
            throw new InvalidArgumentException('Vector must be 3D');
        }
        return [$v[0] * $s, $v[1] * $s, $v[2] * $s];
    }
    
    public static function exp(array $v): array {
        if (count($v) !== 3) {
            throw new InvalidArgumentException('Vector must be 3D');
        }
        return [exp($v[0]), exp($v[1]), exp($v[2])];
    }
}

/**
 * Solar position calculator
 */
class SolarCalculator {
    public static function calculatePosition(float $latitude, float $longitude, ?int $timestamp = null): float {
        if (!is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            throw new SolarPositionException('Latitude must be between -90 and 90 degrees');
        }
        if (!is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new SolarPositionException('Longitude must be between -180 and 180 degrees');
        }
        if ($timestamp !== null && ($timestamp < 0 || $timestamp > 2147483647)) {
            throw new SolarPositionException('Invalid timestamp');
        }
        
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $date = getdate($timestamp);
        $year = $date['year'];
        $month = $date['mon'];
        $day = $date['mday'];
        $hour = $date['hours'];
        $minute = $date['minutes'];
        $second = $date['seconds'];
        
        // Convert to Julian day
        if ($month <= 2) {
            $year -= 1;
            $month += 12;
        }
        $a = floor($year / 100);
        $b = 2 - $a + floor($a / 4);
        $jd = floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
        
        // Add time of day
        $timeOfDay = ($hour + $minute / 60.0 + $second / 3600.0) / 24.0;
        $jd += $timeOfDay;
        
        // Calculate centuries since J2000.0
        $t = ($jd - 2451545.0) / 36525.0;
        
        // Solar longitude (degrees)
        $l0 = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360);
        
        // Mean anomaly (degrees)
        $m = deg2rad(357.52911 + $t * (35999.05029 - 0.0001537 * $t));
        
        // Sun's equation of center
        $c = sin($m) * (1.914602 - $t * (0.004817 + 0.000014 * $t)) +
             sin(2 * $m) * (0.019993 - 0.000101 * $t) +
             sin(3 * $m) * 0.000289;
        
        // True longitude of sun (degrees)
        $sunLon = $l0 + $c;
        
        // Obliquity of ecliptic (degrees)
        $obliq = 23.439291 - $t * (0.0130042 + $t * (0.00000016 - $t * 0.000000504));
        
        // Solar declination (radians)
        $declination = asin(sin(deg2rad($obliq)) * sin(deg2rad($sunLon)));
        
        // Hour angle (radians)
        $longitude_rad = deg2rad($longitude);
        $hourAngle = deg2rad(15 * ($timeOfDay * 24 - 12)) + $longitude_rad;
        
        // Solar elevation (radians)
        $latitude_rad = deg2rad($latitude);
        $elevation = asin(sin($latitude_rad) * sin($declination) + 
                         cos($latitude_rad) * cos($declination) * cos($hourAngle));
        
        return $elevation;
    }
}

/**
 * Atmospheric rendering engine
 */
class AtmosphericRenderer {
    private SkyConfig $config;
    private array $transmittanceCache = [];
    private array $trigCache = [];
    private array $trigCacheOrder = []; // Track access order for LRU
    private array $precomputedSamplePositions = [];
    private bool $positionsCached = false;
    
    public function __construct(SkyConfig $config) {
        $this->config = $config;
    }
    
    /**
     * Clear all caches to free memory
     */
    public function clearCaches(): void {
        $this->transmittanceCache = [];
        $this->trigCache = [];
        $this->trigCacheOrder = [];
        $this->precomputedSamplePositions = [];
        $this->positionsCached = false;
    }
    
    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array {
        return [
            'transmittance_cache_size' => count($this->transmittanceCache),
            'trig_cache_size' => count($this->trigCache),
            'positions_cached' => $this->positionsCached,
            'caching_enabled' => $this->config->enableCaching
        ];
    }
    
    /**
     * ACES tonemapper (Knarkowicz) - with division by zero protection
     */
    private function aces(array $color): array {
        $result = [];
        for ($i = 0; $i < 3; $i++) {
            $c = $color[$i];
            $n = $c * (2.51 * $c + 0.03);
            $d = $c * (2.43 * $c + 0.59) + 0.14;
            
            // Protect against division by zero
            if (abs($d) < 1e-10) {
                $result[$i] = 0.0;
            } else {
                $result[$i] = max(0, min(1, $n / $d));
            }
        }
        return $result;
    }
    
    /**
     * Cache expensive trigonometric calculations
     */
    private function getCachedTrig(string $func, float $value): float {
        if (!$this->config->enableCaching) {
            // Direct calculation if caching is disabled
            switch ($func) {
                case 'sin': return sin($value);
                case 'cos': return cos($value);
                case 'acos': return acos($value);
                case 'exp': return exp($value);
                default: throw new AtmosphericCalculationException("Unsupported trig function: $func");
            }
        }
        
        // Use more precise key generation to avoid floating point precision issues
        $key = $func . '_' . sprintf('%.8f', $value);
        if (!isset($this->trigCache[$key])) {
            // Limit cache size for memory management using LRU
            if (count($this->trigCache) >= $this->config->maxTrigCache) {
                $this->evictLRUTrigCache();
            }
            
            switch ($func) {
                case 'sin': $this->trigCache[$key] = sin($value); break;
                case 'cos': $this->trigCache[$key] = cos($value); break;
                case 'acos': $this->trigCache[$key] = acos($value); break;
                case 'exp': $this->trigCache[$key] = exp($value); break;
                default: throw new AtmosphericCalculationException("Unsupported trig function: $func");
            }
        }
        
        // Update access order for LRU
        $this->updateTrigCacheAccess($key);
        return $this->trigCache[$key];
    }
    
    /**
     * Evict least recently used entries from trig cache
     */
    private function evictLRUTrigCache(): void {
        $removeCount = intval($this->config->maxTrigCache * 0.25); // Remove 25% of cache
        $keysToRemove = array_slice($this->trigCacheOrder, 0, $removeCount);
        
        foreach ($keysToRemove as $key) {
            unset($this->trigCache[$key]);
        }
        
        $this->trigCacheOrder = array_slice($this->trigCacheOrder, $removeCount);
    }
    
    /**
     * Update access order for LRU cache management
     */
    private function updateTrigCacheAccess(string $key): void {
        // Remove key from current position
        $currentPos = array_search($key, $this->trigCacheOrder);
        if ($currentPos !== false) {
            array_splice($this->trigCacheOrder, $currentPos, 1);
        }
        
        // Add key to end (most recently used)
        $this->trigCacheOrder[] = $key;
    }
    
    /**
     * Enhance sunset hues
     */
    private function applySunsetBias(array $color): array {
        $r = $color[0];
        $g = $color[1];
        $b = $color[2];
        
        // Relative luminance (sRGB)
        $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        
        // Weight is higher for darker sky, lower midday
        $w = 1.0 / (1.0 + 2.0 * $lum);
        $k = $this->config->sunsetBiasStrength;
        $rb = 1.0 + 0.5 * $k * $w; // boost red
        $gb = 1.0 - 0.5 * $k * $w; // suppress green
        $bb = 1.0 + 1.0 * $k * $w; // boost blue
        
        return [max(0, $r * $rb), max(0, $g * $gb), max(0, $b * $bb)];
    }
    
    /**
     * Rayleigh phase function (pre-calculated constants with caching)
     */
    private function rayleighPhase(float $angle): float {
        $cosAngle = $this->getCachedTrig('cos', $angle);
        return $this->config->phaseConstants['rayleighScale'] * (1 + $cosAngle * $cosAngle);
    }
    
    /**
     * Mie phase function (pre-calculated constants with caching)
     */
    private function miePhase(float $angle): float {
        $cosAngle = $this->getCachedTrig('cos', $angle);
        $num = $this->config->phaseConstants['mieCoeff'] * (1 + $cosAngle * $cosAngle);
        $denom = $this->config->phaseConstants['mieDenom'] * 
                 pow((1 + $this->config->phaseConstants['mieG2'] - 
                     2 * $this->config->phaseConstants['mieG'] * $cosAngle), 1.5);
        return ($this->config->phaseConstants['mieScale'] * $num) / $denom;
    }
    
    /**
     * Intersect ray with sphere
     */
    private function intersectSphere(array $p, array $d, float $r): ?float {
        $m = $p;
        $b = VectorMath::dot($m, $d);
        $c = VectorMath::dot($m, $m) - $r * $r;
        $discr = $b * $b - $c;
        
        if ($discr < 0) {
            return null; // Ray misses sphere
        }
        
        $t = -$b - sqrt($discr);
        if ($t < 0) {
            return -$b + sqrt($discr); // Ray inside sphere
        }
        return $t;
    }
    
    /**
     * Compute transmittance through atmosphere (with caching)
     */
    private function computeTransmittance(float $height, float $angle): array {
        // Create cache key with consistent precision - only if caching enabled
        if ($this->config->enableCaching) {
            $cacheKey = sprintf('%.3f_%.6f', $height, $angle);
            if (isset($this->transmittanceCache[$cacheKey])) {
                return $this->transmittanceCache[$cacheKey];
            }
        }
        $rayOrigin = [0, $this->config->groundRadius + $height, 0];
        $rayDirection = [sin($angle), cos($angle), 0];
        
        $distance = $this->intersectSphere($rayOrigin, $rayDirection, $this->config->topRadius);
        if ($distance === null) {
            return [1, 1, 1];
        }
        
        $segmentLength = $distance / $this->config->samples;
        $t = 0.5 * $segmentLength;
        
        $odRayleigh = 0;
        $odMie = 0;
        $odOzone = 0;
        
        // Pre-calculate scale heights for performance
        $invRayleighScale = 1.0 / $this->config->rayleighScaleHeight;
        $invMieScale = 1.0 / $this->config->mieScaleHeight;
        
        for ($i = 0; $i < $this->config->samples; $i++) {
            $pos = VectorMath::add($rayOrigin, VectorMath::scale($rayDirection, $t));
            $h = VectorMath::length($pos) - $this->config->groundRadius;
            
            $dR = $this->getCachedTrig('exp', -$h * $invRayleighScale);
            $dM = $this->getCachedTrig('exp', -$h * $invMieScale);
            
            $odRayleigh += $dR * $segmentLength;
            
            // Simple ozone layer (pre-calculated constants)
            $ozoneDensity = 1.0 - min(abs($h - 25e3) / 15e3, 1.0);
            $odOzone += $ozoneDensity * $segmentLength;
            
            $odMie += $dM * $segmentLength;
            $t += $segmentLength;
        }
        
        $tauR = [
            $this->config->rayleighScatter[0] * $odRayleigh,
            $this->config->rayleighScatter[1] * $odRayleigh,
            $this->config->rayleighScatter[2] * $odRayleigh
        ];
        $tauM = [$this->config->mieAbsorb * $odMie, $this->config->mieAbsorb * $odMie, $this->config->mieAbsorb * $odMie];
        $tauO = [
            $this->config->ozoneAbsorb[0] * $odOzone,
            $this->config->ozoneAbsorb[1] * $odOzone,
            $this->config->ozoneAbsorb[2] * $odOzone
        ];
        
        $tau = [
            -($tauR[0] + $tauM[0] + $tauO[0]),
            -($tauR[1] + $tauM[1] + $tauO[1]),
            -($tauR[2] + $tauM[2] + $tauO[2])
        ];
        
        $result = VectorMath::exp($tau);
        
        // Cache result (limit cache size to prevent memory issues)
        if ($this->config->enableCaching && isset($cacheKey) && count($this->transmittanceCache) < $this->config->maxTransmittanceCache) {
            $this->transmittanceCache[$cacheKey] = $result;
        }
        
        return $result;
    }
    
    /**
     * Pre-compute sample positions and directions for reuse
     */
    private function precomputeSampleData(): void {
        if ($this->positionsCached) {
            return;
        }
        
        $invSamplesMinusOne = 1.0 / ($this->config->samples - 1);
        
        for ($i = 0; $i < $this->config->samples; $i++) {
            $s = $i * $invSamplesMinusOne;
            $viewDirection = VectorMath::normalize([0, $s, $this->config->focalZ]);
            
            $this->precomputedSamplePositions[$i] = [
                's' => $s,
                'viewDirection' => $viewDirection,
                'percent' => (1 - $s) * 100
            ];
        }
        
        $this->positionsCached = true;
    }
    
    /**
     * Render sky gradient based on solar elevation
     */
    public function renderGradient(float $altitude): array {
        if (!is_finite($altitude)) {
            throw new AtmosphericCalculationException('Altitude must be a valid finite number');
        }
        
        // Pre-compute sample data for efficiency
        $this->precomputeSampleData();
        
        $cameraPosition = [0, $this->config->groundRadius, 0];
        $sunDirection = VectorMath::normalize([cos($altitude), sin($altitude), 0]);
        
        $stops = [];
        
        // Pre-calculate constants outside the main loop
        $invGamma = 1.0 / $this->config->gamma;
        
        for ($i = 0; $i < $this->config->samples; $i++) {
            $sampleData = $this->precomputedSamplePositions[$i];
            $viewDirection = $sampleData['viewDirection'];
            
            $inscattered = [0, 0, 0];
            
            $tExitTop = $this->intersectSphere($cameraPosition, $viewDirection, $this->config->topRadius);
            if ($tExitTop !== null && $tExitTop > 0) {
                $rayOrigin = $cameraPosition;
                $segmentLength = $tExitTop / $this->config->samples;
                $tRay = $segmentLength * 0.5;
                
                $rayOriginRadius = VectorMath::length($rayOrigin);
                $isRayPointingDownwardAtStart = VectorMath::dot($rayOrigin, $viewDirection) / $rayOriginRadius < 0.0;
                $startHeight = $rayOriginRadius - $this->config->groundRadius;
                $startRayCos = VectorMath::clamp(VectorMath::dot(VectorMath::scale($rayOrigin, 1.0 / $rayOriginRadius), $viewDirection), -1, 1);
                $startRayAngle = acos(abs($startRayCos));
                $transmittanceCameraToSpace = $this->computeTransmittance($startHeight, $startRayAngle);
                
                // Pre-calculate scale height inverses
                $invRayleighScale = 1.0 / $this->config->rayleighScaleHeight;
                $invMieScale = 1.0 / $this->config->mieScaleHeight;
                
                for ($j = 0; $j < $this->config->samples; $j++) {
                    $samplePos = VectorMath::add($rayOrigin, VectorMath::scale($viewDirection, $tRay));
                    $sampleRadius = VectorMath::length($samplePos);
                    $upUnit = VectorMath::scale($samplePos, 1.0 / $sampleRadius);
                    $sampleHeight = $sampleRadius - $this->config->groundRadius;
                    
                    $viewCos = VectorMath::clamp(VectorMath::dot($upUnit, $viewDirection), -1, 1);
                    $sunCos = VectorMath::clamp(VectorMath::dot($upUnit, $sunDirection), -1, 1);
                    $viewAngle = $this->getCachedTrig('acos', abs($viewCos));
                    $sunAngle = $this->getCachedTrig('acos', $sunCos);
                    
                    $transmittanceToSpace = $this->computeTransmittance($sampleHeight, $viewAngle);
                    $transmittanceCameraToSample = [0, 0, 0];
                    
                    for ($k = 0; $k < 3; $k++) {
                        $transmittanceCameraToSample[$k] = $isRayPointingDownwardAtStart
                            ? $transmittanceToSpace[$k] / $transmittanceCameraToSpace[$k]
                            : $transmittanceCameraToSpace[$k] / $transmittanceToSpace[$k];
                    }
                    
                    $transmittanceLight = $this->computeTransmittance($sampleHeight, $sunAngle);
                    
                    $opticalDensityRay = $this->getCachedTrig('exp', -$sampleHeight * $invRayleighScale);
                    $opticalDensityMie = $this->getCachedTrig('exp', -$sampleHeight * $invMieScale);
                    $sunViewCos = VectorMath::clamp(VectorMath::dot($sunDirection, $viewDirection), -1, 1);
                    $sunViewAngle = $this->getCachedTrig('acos', $sunViewCos);
                    $phaseR = $this->rayleighPhase($sunViewAngle);
                    $phaseM = $this->miePhase($sunViewAngle);
                    
                    $scatteredRgb = [0, 0, 0];
                    for ($k = 0; $k < 3; $k++) {
                        $rayleighTerm = $this->config->rayleighScatter[$k] * $opticalDensityRay * $phaseR;
                        $mieTerm = $this->config->mieScatter * $opticalDensityMie * $phaseM;
                        $scatteredRgb[$k] = $transmittanceLight[$k] * ($rayleighTerm + $mieTerm);
                    }
                    
                    for ($k = 0; $k < 3; $k++) {
                        $inscattered[$k] += $transmittanceCameraToSample[$k] * $scatteredRgb[$k] * $segmentLength;
                    }
                    $tRay += $segmentLength;
                }
                
                for ($k = 0; $k < 3; $k++) {
                    $inscattered[$k] *= $this->config->sunIntensity;
                }
            }
            
            // Post-process: exposure → gentle sunset bias → ACES tonemap → gamma → 8-bit RGB
            $color = VectorMath::scale($inscattered, $this->config->exposure);
            $color = $this->applySunsetBias($color);
            $color = $this->aces($color);
            $color = [pow($color[0], $invGamma), pow($color[1], $invGamma), pow($color[2], $invGamma)];
            $rgb = [
                round(VectorMath::clamp($color[0], 0, 1) * 255),
                round(VectorMath::clamp($color[1], 0, 1) * 255),
                round(VectorMath::clamp($color[2], 0, 1) * 255)
            ];
            
            $stops[] = ['percent' => $sampleData['percent'], 'rgb' => $rgb];
        }
        
        // Sort stops and create CSS gradient (optimized string building)
        usort($stops, function($a, $b) {
            return $a['percent'] <=> $b['percent'];
        });
        
        $colorStops = [];
        foreach ($stops as $stop) {
            $rgb = $stop['rgb'];
            $percent = round($stop['percent'], 2); // Single rounding
            $colorStops[] = "rgb({$rgb[0]}, {$rgb[1]}, {$rgb[2]}) {$percent}%";
        }
        
        $gradient = "linear-gradient(to bottom, " . implode(", ", $colorStops) . ")";
        $topColor = $stops[0]['rgb'];
        $bottomColor = $stops[count($stops) - 1]['rgb'];
        
        return [$gradient, $topColor, $bottomColor];
    }
}

/**
 * Main Sky Gradient Generator
 */
class SkyGradientGenerator {
    private SkyConfig $config;
    private AtmosphericRenderer $renderer;
    private SolarCalculator $solarCalculator;
    
    public function __construct(?SkyConfig $config = null) {
        $this->config = $config ?? new SkyConfig();
        $this->renderer = new AtmosphericRenderer($this->config);
        $this->solarCalculator = new SolarCalculator();
    }
    
    public function generate(): array {
        try {
            $sunElevation = $this->solarCalculator->calculatePosition(
                $this->config->latitude, 
                $this->config->longitude
            );
            
            return $this->renderer->renderGradient($sunElevation);
        } catch (SolarPositionException | AtmosphericCalculationException $e) {
            // Log specific atmospheric errors
            error_log('Sky gradient calculation error: ' . $e->getMessage());
            return $this->getFallbackGradient();
        } catch (Exception $e) {
            // Log unexpected errors
            error_log('Unexpected sky gradient error: ' . $e->getMessage());
            return $this->getFallbackGradient();
        }
    }
    
    /**
     * Get consistent fallback gradient values
     */
    private function getFallbackGradient(): array {
        return [
            SkyDefaults::FALLBACK_GRADIENT,
            SkyDefaults::FALLBACK_COLOR,
            SkyDefaults::FALLBACK_COLOR
        ];
    }
    
    /**
     * Get fallback sun elevation
     */
    public function getFallbackSunElevation(): float {
        return SkyDefaults::FALLBACK_SUN_ELEVATION;
    }
    
    /**
     * Get sun elevation with error handling
     */
    public function getSunElevationSafe(): float {
        try {
            return $this->getSunElevation();
        } catch (Exception $e) {
            error_log('Sun elevation calculation error: ' . $e->getMessage());
            return $this->getFallbackSunElevation();
        }
    }
    
    public function getSunElevation(): float {
        return $this->solarCalculator->calculatePosition(
            $this->config->latitude, 
            $this->config->longitude
        );
    }
    
    public function getConfig(): SkyConfig {
        return $this->config;
    }
}

