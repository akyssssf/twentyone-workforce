<?php

namespace App\Services\Fingerspot;

use RuntimeException;

/**
 * Kegagalan saat berbicara dengan API Fingerspot: token ditolak, perangkat
 * tidak dikenal, jaringan putus, atau respons di luar dugaan.
 */
class FingerspotException extends RuntimeException {}
