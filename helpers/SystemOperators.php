<?php
    class SystemOperators {
        private $secretKey = '1234567890abcdef1234567890abcdef';
        private $cipher = 'AES-256-CBC';
        private $iv = '1234567890123456';
        
        public function encrypt($value) {
            return base64_encode(openssl_encrypt($value, $this->cipher, $this->secretKey, 0, $this->iv));
        }
        public function decrypt($encrypted) {
            return openssl_decrypt(base64_decode($encrypted), $this->cipher, $this->secretKey, 0, $this->iv);
        }
        public function randomStringGenerator($length = 8) {
            return bin2hex(random_bytes($length / 2));
        }
    }
?>