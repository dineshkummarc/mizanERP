<?php

namespace App\Support\WhatsApp;

use RuntimeException;

/**
 * A message that did not go. Carries what the provider said, because "sending
 * failed" is useless to whoever has to fix it — an unregistered number, a
 * template still in review and an expired token all need different actions.
 */
class WhatsAppException extends RuntimeException {}
