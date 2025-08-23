# Sky Gradient

The current sky at a given location, rendered as a CSS gradient based on atmospheric physics. Refreshes every minute.

PHP port of the original JavaScript/TypeScript implementation by [Daniel Lazaro](https://github.com/dnlzro/horizon) (deployed at: https://sky.dlazaro.ca). 

IP geolocation feature removed for simplicity and privacy.

## Physics Implementation

- Correctly implements Rayleigh and Mie scattering with proper coefficients
- Includes ozone absorption layer modeling
- Uses appropriate scale heights for different atmospheric components
- Implements realistic phase functions for both scattering types


## Attribution

- Physical model and parameter choices originally appeared in ["A Scalable and Production Ready Sky and Atmosphere Rendering Technique"](https://onlinelibrary.wiley.com/doi/10.1111/cgf.14050) (Sébastien Hillaire).
- Implementation derived from ["Production Sky Rendering"](https://www.shadertoy.com/view/slSXRW) (Andrew Helmer, MIT License).
