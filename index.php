<?php
// Temporary redirect to the public directory when running in Docker
header("Location: /public/");
exit;
