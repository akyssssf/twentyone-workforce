<?php

namespace App\Services\Fingerspot;

use RuntimeException;

/**
 * Payload rusak secara permanen: PIN hilang, waktu scan tidak bisa dibaca, dan
 * sejenisnya.
 *
 * Dibedakan dari kegagalan sementara (database mati, disk penuh) karena
 * penanganannya berlawanan. Yang permanen tidak boleh diulang selamanya, jadi
 * callback-nya ditandai parsed dan alasannya disimpan di parse_error. Yang
 * sementara justru harus tetap di antrian supaya dicoba lagi.
 */
class InvalidScanPayload extends RuntimeException {}
