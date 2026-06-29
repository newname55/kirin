<?php

declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
$location = '/crew-onboarding/public/' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $location, true, 301);
exit;
