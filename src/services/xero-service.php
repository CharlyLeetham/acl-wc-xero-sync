public static function get_client() {
    if ( ! self::$xero ) {
        $consumer_key = get_option( 'acl_xero_consumer_key', '' );
        $consumer_secret = get_option( 'acl_xero_consumer_secret', '' );

        if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
            throw new \Exception( 'Xero API keys are not set. Please configure them in the settings.' );
        }

        $config = [
            'oauth' => [
                'consumer_key'    => $consumer_key,
                'consumer_secret' => $consumer_secret,
            ],
            'private_key_path' => __DIR__ . '/../../privatekey.pem', // Adjust as needed
        ];
        self::$xero = new PrivateApplication( $config );
    }
    return self::$xero;
}
