<?php

/*
 * Production entry point for the PHP backend.
 *
 * Web root for firol.cstudios.ninja is frontend/dist/. Files placed in
 * frontend/public/ are auto-copied to dist/ by Vite during build, so this
 * file ends up at <docroot>/api.php on the server.
 *
 * It just delegates to the real front controller in backend/public/index.php,
 * which lives one level up outside the web root.
 */

require __DIR__ . '/../../backend/public/index.php';
