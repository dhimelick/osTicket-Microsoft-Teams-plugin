<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once('config.php');

class TeamsPlugin extends Plugin {

    var $config_class = "TeamsPluginConfig";
    var $backend;

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        $backend = new TeamsBackend($this->getConfig());       
    }
}

class TeamsBackend {
    var $config;

    function __construct($config) {
        $this->config = $config;

        Signal::connect('ticket.created', function(Ticket $ticket) {
            $this->onTicketCreated($ticket);
        });

        Signal::connect('threadentry.created', function(ThreadEntry $entry) {
            $this->onTicketUpdated($entry);
        });
    }

    /**
     * Post to Teams when a new ticket is created.
     */
    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Teams plugin called too early.");
            return;
        }

        $subjPrefix = $ticket->getNumber() . ' created: ';
        $this->sendToTeams($ticket, $subjPrefix);
    }

    /**
     * Post to Teams when a ticket is updated.
     */
    function onTicketUpdated(ThreadEntry $entry) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Teams plugin called too early.");
            return;
        }

        if (!$entry instanceof MessageThreadEntry) {
            return;
        }

        // verify this is a ticket thread, not a task thread
        $threadType = Thread::objects()->filter([
            'id' => $entry->getThreadId()
        ])->values_flat('object_type')->first() [0];
        if ($threadType != "T") {
            return;
        }

        $ticket = TeamsBackend::getTicket($entry);
        if (!$ticket instanceof Ticket) {
            return;
        }

        $firstEntry = $ticket->getMessages()[0];
        if ($entry->getId() == $firstEntry->getId()) {
            return;
        }
        
        $subjPrefix = $ticket->getNumber() . ' updated: ';
        $this->sendToTeams($ticket, $subjPrefix);
    }

    /**
     * Send a message to Teams.
     */
    function sendToTeams(Ticket $ticket, string $subjPrefix) {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Teams plugin called too early.");
            return;
        }
    
        $url = $this->config->get('teams-webhook-url');
        if (!$url) {
            $ost->logError('Teams Plugin not configured', 'You need to read the Readme and configure a webhook URL before using this.');
        }
    
        // check the subject for filtering
        $regexSubjectIgnore = $this->config->get('teams-regex-subject-ignore');
        if ($regexSubjectIgnore && preg_match("/$regexSubjectIgnore/i", $ticket->getSubject())) {
            $ost->logDebug('Ignored Message', 'Teams notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regexSubjectIgnore) . ').');
            return;
        }
    
        // build the payload with the formatted data
        $payload = $this->createJsonMessage($ticket, $subjPrefix);
    
        try {
            // set up curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload))
            );
    
            // send the payload to Teams
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
     * Create JSON payload for Teams card.
     */
    function createJsonMessage(Ticket $ticket, string $subjPrefix = '')
    {
        global $cfg;
    
        $entry = $ticket->getLastMessage();
    
        $message = [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => 'Ticket: ' . $ticket->getNumber(),
            'title' => $subjPrefix . $ticket->getSubject(),
            'sections' => [
                [
                    'activityTitle' => 'From ' . $entry->getName() . ' (' . $entry->getUser()->getEmail() . ')',
                    'activitySubtitle' => is_string($entry->getCreateDate()) ? $entry->getCreateDate() : $ticket->getCreateDate()
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
    
        // add the last tikcet message to the card if configured
        if($this->config->get('teams-message-display')) {
            array_push($message['sections'], ['text' => trim(substr($entry->getBody()->getClean(), 0, 300)) . '...']);
        }
        
        return json_encode($message, JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Fetch a Ticket from a ThreadEntry.
     */
    static function getTicket(ThreadEntry $entry) {
        $ticketId = Thread::objects()->filter([
            'id' => $entry->getThreadId()
        ])->values_flat('object_id')->first()[0];
    
        return Ticket::lookup(array(
            'ticket_id' => $ticketId
        ));
    }
}
