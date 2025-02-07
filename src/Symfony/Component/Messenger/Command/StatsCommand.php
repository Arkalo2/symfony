<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * @author Kévin Thérage <therage.kevin@gmail.com>
 */
#[AsCommand(name: 'messenger:stats', description: 'Show the message count for one or more transports')]
class StatsCommand extends Command
{
    public function __construct(
        private ContainerInterface $transportLocator,
        private array $transportNames = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $outputFormats = implode(', ', $this->getAvailableFormatOptions());
        $this
            ->addArgument('transport_names', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'List of transports\' names')
            ->addOption('format', '', InputOption::VALUE_REQUIRED, 'The output format, e.g.: '.$outputFormats, 'text', $this->getAvailableFormatOptions())
            ->setHelp(<<<EOF
The <info>%command.name%</info> command counts the messages for all the transports:

    <info>php %command.full_name%</info>

Or specific transports only:

    <info>php %command.full_name% <transportNames></info>

The <info>--format</info> option specifies the format of command output,
these are "{$outputFormats}".

  <info>php %command.full_name% --format=json</info>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);

        $format = $input->getOption('format');
        if (!\in_array($format, $this->getAvailableFormatOptions(), true)) {
            throw new InvalidArgumentException('Invalid output format.');
        }

        $transportNames = $this->transportNames;
        if ($input->getArgument('transport_names')) {
            $transportNames = $input->getArgument('transport_names');
        }

        $outputTable = [];
        $uncountableTransports = [];
        foreach ($transportNames as $transportName) {
            if (!$this->transportLocator->has($transportName)) {
                if ($this->formatSupportsWarnings($format)) {
                    $io->warning(\sprintf('The "%s" transport does not exist.', $transportName));
                }

                continue;
            }
            $transport = $this->transportLocator->get($transportName);
            if (!$transport instanceof MessageCountAwareInterface) {
                $uncountableTransports[] = $transportName;

                continue;
            }
            $outputTable[] = [$transportName, $transport->getMessageCount()];
        }

        match ($format) {
            'text' => $this->outputText($io, $outputTable, $uncountableTransports),
            'json' => $this->outputJson($io, $outputTable, $uncountableTransports),
        };

        return 0;
    }

    private function outputText(SymfonyStyle $io, array $outputTable, array $uncountableTransports): void
    {
        $io->table(['Transport', 'Count'], $outputTable);

        if ($uncountableTransports) {
            $io->note(\sprintf('Unable to get message count for the following transports: "%s".', implode('", "', $uncountableTransports)));
        }
    }

    private function outputJson(SymfonyStyle $io, array $outputTable, array $uncountableTransports): void
    {
        $output = ['transports' => []];
        foreach ($outputTable as [$transportName, $count]) {
            $output['transports'][$transportName] = ['count' => $count];
        }

        if ($uncountableTransports) {
            $output['uncountable_transports'] = $uncountableTransports;
        }

        $io->writeln(json_encode($output, \JSON_PRETTY_PRINT));
    }

    private function formatSupportsWarnings(string $format): bool
    {
        return match ($format) {
            'text' => true,
            'json' => false,
        };
    }

    /** @return string[] */
    private function getAvailableFormatOptions(): array
    {
        return ['text', 'json'];
    }
}
