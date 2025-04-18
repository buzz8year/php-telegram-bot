<?php

class Telegram
{
    public $token;
    public $mysqli;
    public $domain;


    public function __construct($mysqli, $domain)
    {
        // $this->token    = $token;
        $this->mysqli   = $mysqli;
        $this->domain = $domain;
        $this->mysqli->set_charset('utf8');
    }


    public function sendMessage($chat_id, $text, $reply)
    {
        /*$connection = curl_init();
        curl_setopt($connection, CURLOPT_URL, "https://api.telegram.org/{$this->token}/sendMessage?chat_id=$chat_id&text=$text&reply_markup=$reply");
        $res = curl_exec($connection);
        curl_close($connection);*/

        // $res = file_get_contents("https://api.telegram.org/{$this->token}/sendMessage?chat_id=$chat_id&text=$text&parse_mode=HTML&reply_markup=$reply");
        // return json_decode($res);
        return json_encode($text) . json_encode($reply);
    }


    public function makeReply($array)
    {
        array_push(
            $array,
            [[
                "text"          => "Menu",
                "callback_data" => json_encode([
                    "function" => "main_menu",
                ]),
            ]]
        );
        return json_encode([
            "inline_keyboard" => $array,
        ]);
    }


    public function makeForceReply()
    {
        return json_encode([
            "force_reply" => true,
        ]);
    }


    private function start_message($chat_id)
    {
        $this->sendMessage(
            $chat_id,
            "Hello! Select action:",
            $this->makeReply([[[
                "text"          => "Search",
                "callback_data" => json_encode([
                    "function" => "search_items",
                ]),
            ]]])
        );

        //#withoffer $this->makeReply([[array("text" => "Search", "callback_data" => json_encode(array("function" => "search_items")))], [array("text" => "Promo", "callback_data" => json_encode(array("function" => "show_offer")))]]));
    }


    public function messageHook($message)
    {
        switch ($message->text) {
            case "/start":{
                    $this->sendMessage($message->chat->id,
                            "Hello! Select action:",
                            //#withoffer $this->makeReply([[array("text" => "Search", "callback_data" => json_encode(array("function" => "search_items")))], [array("text" => "Promo", "callback_data" => json_encode(array("function" => "show_offer")))]]));                                       $this->makeReply([[[
                            "text"          => "Search",
                            "callback_data" => json_encode([
                                "function" => "search_items",
                            ]),
                        ]]])
                    );

                    return;
                }
        }

        if ($message->reply_to_message->message_id) {

            $message_id = $message->reply_to_message->message_id;
            $user_id    = $message->chat->id;
            $r          = $this->mysqli->query("SELECT * FROM `telegram_messages` WHERE `id` = '{$message_id}' AND `user_id` = {$user_id}");
            $rs         = $r->fetch_object();

            if ($r->num_rows) {
                $session = $this->get_session($rs->session_id);

                if ($session != null) {
                    if ($rs->data == "search_all_names") {

                        $session->step         = 5;
                        $session->min          = 0;
                        $session->max          = 0;
                        $session->man_id       = 0;

                        $session->text_request = $this->mysqli->escape_string($message->text);

                        $this->update_session($session);
                        $this->step($session, $message);
                        return;
                    }
                    switch ($session->step) {
                        case 1:{
                                $session->step = 2;
                                $session->min  = intval($message->text);
                                $this->update_session($session);
                                $this->step($session, $message);
                                break;
                            }
                        case 2:{
                                $session->step = 3;
                                $session->max  = intval($message->text);
                                $this->update_session($session);
                                $this->step($session, $message);
                                break;
                            }
                        case 4:{
                                $session->step         = 5;
                                $session->text_request = $this->mysqli->escape_string($message->text);
                                $this->update_session($session);
                                $this->step($session, $message);
                                break;
                            }
                    }
                }
            }

        } else {
            $this->start_message($message->chat->id);
        }

        return true;
    }

    public function ReplyKeyboardRemove()
    {
        return json_encode([
            "remove_keyboard" => true
        ]);
    }

    public function callbackHook($callback)
    {
        $data = json_decode($callback->data);

        switch ($data->function) {

            case "main_menu":{
                    $this->start_message($callback->message->chat->id);
                    break;
                }



            case "search_items_by_name":{
                    $message = $this->sendMessage(
                        $callback->message->chat->id,
                        "Type a name to search:",
                        $this->makeForceReply()
                    );

                    $session = $this->new_session($callback->message->chat->id, "*");

                    $this->add_message(
                        $session->id,
                        $callback->message->chat->id,
                        $message->result->message_id,
                        "search_all_names"
                    );
                    break;
                }


            case "show_offer":{
                    $offers = [];

                    $req = $this->mysqli->query("SELECT * FROM `oc_product_special`");
                    //$this->sendMessage($callback->message->chat->id, $req->num_rows, null);

                    while ($row = $req->fetch_assoc()) {

                        $reqs = $this->mysqli->query("SELECT * FROM `oc_product` WHERE `product_id` = '" . $row["product_id"] . "'")->fetch_assoc();
                        $row  = array_merge($row, $reqs);
                        $find = false;

                        for ($i = 0; $i < count($offers); $i++) {
                            if ($offers[$i]["product_id"] == $row["product_id"]) {

                                if ($offers[$i]["priority"] < $row["priority"]) {
                                    $offers[$i]["price"]    = $row["price"];
                                    $offers[$i]["priority"] = $row["priority"];
                                }

                                $find = true;
                            }
                        }

                        if (!$find) {
                            array_push($offers, $row);
                        }

                    }

                    //$this->sendMessage($callback->message->chat->id, json_encode($offers), null);
                    $this->messageProducts(
                        $callback->message->chat->id, 
                        json_decode(json_encode($offers), false), 
                        "*"
                    );

                    break;
                }


        }
        return true;
    }



    private function add_message($session_id, $user_id, $message_id, $message_data)
    {
        $this->mysqli->query("INSERT INTO `telegram_messages` (`id`, `user_id`, `session_id`, `data`) VALUES ('$message_id', '$user_id', '$session_id', '$message_data')");
    }


    private function new_session($user_id, $category_id)
    {
        //$this->mysqli->query("DELETE FROM `telegram_sessions` WHERE `user_id` = '$user_id'");
        $this->mysqli->query("INSERT INTO `telegram_sessions` (`user_id`, `category_id`, `step`) VALUES ('$user_id', '$category_id', '1')");
        return $this->mysqli->query("SELECT * FROM `telegram_sessions` WHERE `user_id` = '$user_id' ORDER BY `id` DESC")->fetch_object();
    }


    private function get_session($session_id)
    {
        return $this->mysqli->query("SELECT * FROM `telegram_sessions` WHERE `id` = '$session_id'")->fetch_object();
    }


    private function update_session($session)
    {
        $this->mysqli->query("UPDATE `telegram_sessions` SET `step` = '{$session->step}', `min` = '{$session->min}', `max` = '{$session->max}', `man_id`  = '{$session->man_id}', `text_request` = '{$session->text_request}' WHERE `id` = '$session->id'");
    }

}
