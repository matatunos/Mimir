<?php
/**
 * Notification helper
 * Simple wrapper providing a unified interface for sending notifications.
 */
require_once __DIR__ . '/Email.php';

class Notification {
    protected $emailSender;

    public function __construct($emailSender = null) {
        if ($emailSender === null) {
            $this->emailSender = new Email();
        } else {
            $this->emailSender = $emailSender;
        }
    }

    /**
     * Send a notification. Uses Email by default.
     * Returns true on success, false on failure.
     */
    public function send($recipient, $subject, $body, $options = []) {
        try {
            return (bool)$this->emailSender->send($recipient, $subject, $body, $options);
        } catch (Exception $e) {
            error_log('Notification::send error: ' . $e->getMessage());
            return false;
        }
    }
}
