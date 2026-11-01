<?php
/**
 * Encryption handler for API credentials
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF_Encryption {
    
    /**
     * Encrypt data using WordPress salts
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        $method = 'AES-256-CBC';
        $key = self::get_key();
        $iv = self::get_iv();
        
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        return base64_encode($encrypted);
    }
    
    /**
     * Decrypt data
     */
    public static function decrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        $method = 'AES-256-CBC';
        $key = self::get_key();
        $iv = self::get_iv();
        
        $decrypted = openssl_decrypt(base64_decode($data), $method, $key, 0, $iv);
        return $decrypted;
    }
    
    /**
     * Get encryption key from WordPress salts
     */
    private static function get_key() {
        $salt = AUTH_KEY . SECURE_AUTH_KEY;
        return substr(hash('sha256', $salt), 0, 32);
    }
    
    /**
     * Get IV from WordPress salts
     */
    private static function get_iv() {
        $salt = AUTH_SALT . SECURE_AUTH_SALT;
        return substr(hash('sha256', $salt), 0, 16);
    }
}
