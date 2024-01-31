<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');

class TeamsPlugin extends Plugin {

    var $config_class = "TeamsPluginConfig";

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        $config = $this->getConfig();

        Signal::connect('ticket.created', function(Ticket $ticket) use ($config) {
            global $cfg;
            if (!$cfg instanceof OsticketConfig) {
                error_log("Teams plugin called too early.");
                return;
            }

            $type = $ticket->getNumber() . ' created: ';
            TeamsPlugin::sendToTeams($ticket, $type, 'good', $config);
        });

        Signal::connect('threadentry.created', function(ThreadEntry $entry) use ($config) {
            global $cfg;
            if (!$cfg instanceof OsticketConfig) {
                error_log("Teams plugin called too early.");
                return;
            }

            if (!$entry instanceof MessageThreadEntry) {
                // this was a reply or a system entry, not a message from a user
                return;
            }

            // fetch the ticket from the ThreadEntry
            $ticket = TeamsPlugin::getTicket($entry);

            if (!$ticket instanceof Ticket) {
                return;
            }

            // make sure this entry isn't the first (i.e., a new ticket)
            $first_entry = $ticket->getMessages()[0];
            if ($entry->getId() == $first_entry->getId()) {
                return;
            }

            $type = $ticket->getNumber() . ' updated: ';
            TeamsPlugin::sendToTeams($ticket, $type, 'warning', $config);
        });
    }

    /**
     * A helper function that sends messages to teams endpoints.
     *
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $type
     * @param string $colour
     * @throws \Exception
     */
    static function sendToTeams(Ticket $ticket, $type, $colour = 'good', $config) {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Teams plugin called too early.");
            return;
        }
        $url = $config->get('teams-webhook-url');
        if (!$url) {
            $ost->logError('Teams Plugin not configured', 'You need to read the Readme and configure a webhook URL before using this.');
        }

        // Check the subject, see if we want to filter it.
        $regex_subject_ignore = $config->get('teams-regex-subject-ignore');
        // Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            $ost->logDebug('Ignored Message', 'Teams notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            return;
        }

        // Build the payload with the formatted data:
        $payload = TeamsPlugin::createJsonMessage($ticket, $config->get('teams-message-display'), $type);

        try {
            // Setup curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload))
            );

            // Actually send the payload to Teams:
            if (curl_exec($ch) === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') {
                    throw new \Exception(
                        'Error sending to: ' . $url
                        . ' Http code: ' . $statusCode
                        . ' curl-error: ' . curl_errno($ch));
                }
            }
        } catch (\Exception $e) {
            $ost->logError('Teams posting issue!', $e->getMessage(), true);
            error_log('Error posting to Teams. ' . $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry
     * @return Ticket
     */
    static function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter([
            'id' => $entry->getThreadId()
        ])->values_flat('object_id')->first() [0];

        // Force lookup rather than use cached data..
        // This ensures we get the full ticket, with all
        // thread entries etc..
        return Ticket::lookup(array(
            'ticket_id' => $ticket_id
        ));
    }

    /**
     * Formats text according to the
     * formatting rules:https://docs.microsoft.com/en-us/outlook/actionable-messages/adaptive-card
     *
     * @param string $text
     * @return string
     */
    static function format_text($text) {
        $formatter      = [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;'
        ];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        // put the <>'s control characters back in
        $moreformatter  = [
            'CONTROLSTART' => '<',
            'CONTROLEND'   => '>'
        ];
        // Replace the CONTROL characters, and limit text length to 500 characters.
        return substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source https://gravatar.com/site/implement/images/php/
     */
    static function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array()) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

    /**
     * @param $ticket
     * @param string $color
     * @param null $type
     * @return false|string
     */
    static function createJsonMessage($ticket, $messageDisplay, $type = null, $color = 'AFAFAF')
    {
        global $cfg;
        if ($ticket->isOverdue()) {
            $color = 'ff00ff';
        }
        //Prepare message array to convert to json
        $message = [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => 'Ticket: ' . $ticket->getNumber(),
            'themeColor' => $color,
            'title' => TeamsPlugin::format_text($type . $ticket->getSubject()),
            'sections' => [
                [
                    'activityTitle' => ($ticket->getName() ? $ticket->getName() : 'Guest ') . ' (sent by ' . $ticket->getEmail() . ')',
                    'activitySubtitle' => is_string($ticket->getUpdateDate()) ? $ticket->getUpdateDate() : $ticket->getCreateDate(),
                    'activityImage' => TeamsPlugin::get_gravatar($ticket->getEmail()),
                ],
            ],
            'potentialAction' => [
                [
                    '@type' => 'OpenUri',
                    'name' => 'View ' . $ticket->getNumber(),
                    'targets' => [
                        [
                            'os' => 'default',
                            'uri' => $cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId(),
                        ]
                    ]
                ]
            ]
        ];
        if($messageDisplay) {
            array_push($message['sections'], ['text' => trim(substr($ticket->getLastMessage()->getBody()->getClean(), 0, 300)) . '...']);
        }

        return json_encode($message, JSON_UNESCAPED_SLASHES);

    }

}
