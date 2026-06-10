<?php

declare(strict_types=1);

namespace Vortos\Setup\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vortos\Setup\Console\TerminalMenu;

final class TerminalMenuTest extends TestCase
{
    public function test_menu_is_disabled_for_non_interactive_input(): void
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $output->setDecorated(true);

        $menu = new TerminalMenu();

        $this->assertFalse($menu->supports($input, $output));
        $this->assertNull($menu->choose($input, $output, 'Choose', ['a', 'b'], 'a'));
    }

    public function test_menu_is_disabled_for_plain_output(): void
    {
        $input = new ArrayInput([]);
        $input->setInteractive(true);
        $output = new BufferedOutput();
        $output->setDecorated(false);

        $this->assertFalse((new TerminalMenu())->supports($input, $output));
    }
}
