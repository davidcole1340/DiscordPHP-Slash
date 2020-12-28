<?php

namespace Discord\Slash\Enums;

/**
 * @link https://discord.com/developers/docs/interactions/slash-commands#applicationcommandoptiontype
 * @author David Cole <david.cole1340@gmail.com>
 */
final class ApplicationCommandOptionType
{
    public const SUB_COMMAND = 1;
    public const SUB_COMMAND_GROUP = 2;
    public const STRING = 3;
    public const INTEGER = 4;
    public const BOOLEAN = 5;
    public const USER = 6;
    public const CHANNEL = 7;
    public const ROLE = 8;
}
