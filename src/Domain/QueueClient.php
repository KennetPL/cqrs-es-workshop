<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 13:32
 */

namespace Domain;


interface QueueClient
{
    public function sendMessage($messageBody);
}