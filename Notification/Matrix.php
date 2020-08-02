<?php

namespace Kanboard\Plugin\Matrix\Notification;

use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
use Kanboard\Plugin\Matrix\Api;

/**
 * Matrix Notification
 *
 * @package  notification
 * @author   Andrew Shadura
 */
class Matrix extends Base implements NotificationInterface
{
    private $api = null;
    /**
     * Send notification to a user
     *
     * @access public
     * @param  array     $user
     * @param  string    $eventName
     * @param  array     $eventData
     */
    public function notifyUser(array $user, $eventName, array $eventData)
    {
    }

    /**
     * Send notification to a project
     *
     * @access public
     * @param  array     $project
     * @param  string    $event_name
     * @param  array     $event_data
     */
    public function notifyProject(array $project, $event_name, array $event_data)
    {
        if (empty($this->configModel->get('matrix_homeserver_url'))) {
            return;
        }

        $room = $this->projectMetadataModel->get($project['id'], 'matrix_room');

        if (!empty($room)) {
            $this->sendMessage($room, $project, $event_name, $event_data);
        }
    }

    /**
     * Get message to send
     *
     * @access public
     * @param  array     $project
     * @param  string    $event_name
     * @param  array     $event_data
     * @return string
     */
    public function getMessage(array $project, $event_name, array $event_data)
    {
        $use_colours = $this->projectMetadataModel->get($project['id'], 'matrix_use_colours');
        if (!isset($use_colours)) {
            $use_colours = true;
        }

        if ($this->userSession->isLogged()) {
            $author = $this->helper->user->getFullname();
            $title = $this->notificationModel->getTitleWithAuthor($author, $event_name, $event_data);
        } else {
            $title = $this->notificationModel->getTitleWithoutAuthor($event_name, $event_data);
        }

        $message  = $use_colours ? '<font color="green">' : '';
        $message .= htmlspecialchars($title);
        $message .= ($use_colours ? '</font>': '').' (<b>'.htmlspecialchars($event_data['task']['title'])."</b>) ";

        if ($this->configModel->get('application_url') !== '') {
            $url = $this->helper->url->to('TaskViewController', 'show', array('task_id' => $event_data['task']['id'], 'project_id' => $project['id']), '', true);
            $message .= $use_colours ? '<font color="teal">' : '';
            $message .= htmlspecialchars($url);
            $message .= $use_colours ? '</font>' : '';
        }
        $message .= $this->handleEvent($project, $event_name, $event_data);

        return $message;
    }
     /**
     * 
     * https://docs.kanboard.org/en/latest/developer_guide/webhooks.html?highlight=event_data#list-of-supported-events
     *
     * @access private
     * @param  array    $project
     * @param  string   $event_name
     * @param  array    $event_data
     *
     */
    private function handleEvent(array $project, $event_name, array $event_data)
    {
        switch($event_name) {
            case "comment.create":
            case "comment.update";
                $use_embed_comment = $this->projectMetadataModel->get($project['id'], 'matrix_embed_comments');
                if (!isset($use_embed_comment)) {
                    $use_embed_comment = true;
                }
                return $use_embed_comment ? $this->handleComment($event_data) : '';
            case "task.create":
            case "task.update";
                $use_embed_description = $this->projectMetadataModel->get($project['id'], 'matrix_embed_description');
                if (!isset($use_embed_description)) {
                    $use_embed_description = true;
                }
                return $use_embed_description ? $this->handleDescription($event_data) : '';
            default:
                return '';
        }
    }
    /**
     * comment.create comment.update handler
     * https://docs.kanboard.org/en/latest/developer_guide/webhooks.html?highlight=event_data#examples-of-event-payloads
     *
     * @access private
     * @param  string    $event_data
     *
     */
    private function handleComment(array $event_data)
    {
        return '<p>' . htmlspecialchars($event_data['comment']['comment']) . '</p>';
    }

    /**
     * task.create task.update handler
     * https://docs.kanboard.org/en/latest/developer_guide/webhooks.html?highlight=event_data#list-of-supported-events
     *
     * @access private
     * @param  string    $event_data
     *
     */
    private function handleDescription(array $event_data)
    {
        return '<p>' . htmlspecialchars($event_data['task']['description']) . '</p>';
    }

    /**
     * Send message to Matrix
     *
     * @access private
     * @param  string    $room
     * @param  array     $project
     * @param  string    $event_name
     * @param  array     $event_data
     */
    private function sendMessage($room, array $project, $event_name, array $event_data)
    {
        if (!isset($this->api)) {
            $homeserver_url = $this->configModel->get('matrix_homeserver_url');
            $token = $this->configModel->get('matrix_token');
            if (empty($token)) {
                $username = $this->configModel->get('matrix_username');
                $password = $this->configModel->get('matrix_password');
                if (empty($username) && empty($password)) {
                    $this->api = new Api($this->container, $homeserver_url);
                    $this->api->login($username, $password);
                } else {
                    return;
                }
            } else {
                    $this->api = new Api($this->container, $homeserver_url, $token);
            }
        }
        $html_content = $this->getMessage($project, $event_name, $event_data);
        $text_content = htmlspecialchars_decode(strip_tags($html_content));

        $send_notices = $this->projectMetadataModel->get($project['id'], 'matrix_send_notices');
        if (!isset($send_notices)) {
            $send_notices = true;
        }

        $r = $this->api->joinRoom($room);
        if (!isset($r)) {
            trigger_error("Failed to join Matrix room ".$room);
        } else {
            $this->api->sendMessage($r->room_id, $text_content, $html_content, $send_notices ? 'm.notice' : 'm.text');
        }
    }
}
