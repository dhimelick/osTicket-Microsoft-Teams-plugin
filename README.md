# Microsoft Teams Plugin for osTicket

A plugin for [osTicket](https://osticket.com) which posts notifications to a [Microsoft Teams](https://products.office.com/en-us/microsoft-teams/group-chat-software) channel.

Forked from [https://github.com/Data-Tech-International/osTicket-Microsoft-Teams-plugin](https://github.com/Data-Tech-International/osTicket-Microsoft-Teams-plugin) which was originally forked from [https://github.com/clonemeagain/osticket-slack](https://github.com/clonemeagain/osticket-slack).

This plugin has been updated to work with osTicket 1.18 on PHP 8.1+.

## Requirements

- php_curl
- An Office 365 account for Teams

## Microsoft Teams Setup

**Important Note**: As of 2024-02-01, you _must_ use the "old" Teams to manage Connections including incoming webhooks. Once configured, you can use the "new" Teams and will continue to receive osTicket notifications.

1. In Teams, click **...** for the channel you'd like to receive notifications and select **Connectors**.
2. Search for "incoming webhook" and click **Add**.
3. Enter a webhook name (which will be the chat "user") and optionally a profile image. Click **Create**.
4. Copy the generated URL for use in step 7 below.

The channel you select will receive a notification similar to this:

```
David Himelick has set up a connection to Incoming Webhook so group members will be notified for this configuration with name osTicket
```

## Plugin Installation

1. Clone this repo or download the zip file and place the contents into your `include/plugins` folder.
2. Login to osTicket.
3. Go to **Admin Panel** then **Manage > Plugins**.
4. Click **Add New Plugin** and click **Install** for "Microsoft Teams Notifications".
5. Go to the **Instances** tab and click **Add New Instance**.
6. On the **Instance** tab, give the instance a descriptive name and change the status to **Enabled**.
7. On the Config tab, enter the incoming webhook URL obtained from Teams using the steps above and click **Add Instance**.
8. Navigate back to **Manage > Plugins**.
9. Check the box next to Microsoft Teams Notifications then select **More > Enable**.

## Test

Create a ticket. Submit an update to that ticket as a user. Internal notes, replies from agents, and system messages shouldn't appear.
