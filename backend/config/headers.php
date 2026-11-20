<?php

// The security headers of every answer of the platform, from their single source of truth,
// resources/http/security-headers.json, which the CDK app reads too for the headers the edge gives the web app
// (App\Http\SecurityHeaderPolicy reads this configuration). Cached with the rest of the configuration in the
// functions.
return json_decode((string) file_get_contents(resource_path('http/security-headers.json')), true, flags: JSON_THROW_ON_ERROR);
