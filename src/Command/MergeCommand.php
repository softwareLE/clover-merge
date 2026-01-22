<?php

namespace Kavinsky\CloverMerge\Command;

use Kavinsky\CloverMerge\Accumulator;
use Kavinsky\CloverMerge\ArgumentException;
use Kavinsky\CloverMerge\FileException;
use Kavinsky\CloverMerge\Utilities;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MergeCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('merge')
            ->setDescription('Report the coverage to bitbucket')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path'
            )
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Merge mode: additive, exclusive or inclusive (default)',
                'inclusive'
            )
            ->addOption(
                'enforce',
                'e',
                InputOption::VALUE_OPTIONAL,
                'Exit with failure if final coverage is below the given threshold',
                '0'
            )
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY,
                'Input file paths'
            );
    }

    /**
     * @throws ArgumentException
     * @throws FileException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accumulator = new Accumulator(
            $this->mode($input->getOption('mode'))
        );

        // Parse
        $accumulator->parseAll(
            $this->documents(
                $this->paths($input->getArgument('paths'))
            )
        );

        // Output
        if (empty($input->getOption('output'))) {
            throw new \InvalidArgumentException('Missing required option: output');
        }

        [$xml, $metrics] = $accumulator->toXml();
        $write_result = file_put_contents($input->getOption('output'), $xml);
        if ($write_result === false) {
            throw new FileException('Unable to write to given output file.');
        }

        // Stats
        $coverage_threshold = floatval($input->getOption('enforce'));
        $files_discovered = $metrics->file_count;
        $element_count = $metrics->getElementCount();
        $covered_element_count = $metrics->getCoveredElementCount();

        if ($element_count === 0) {
            $coverage_percentage = 0;
        } else {
            $coverage_percentage = 100 * $covered_element_count/$element_count;
        }

        $output->writeln(sprintf("Files Discovered: %d", $files_discovered));
        $output->writeln(
            sprintf(
                "Final Coverage: %d/%d (%.2f%%)",
                $covered_element_count,
                $element_count,
                $coverage_percentage
            )
        );
        $success = $coverage_percentage > $coverage_threshold;
        if ($coverage_threshold > 0) {
            if ($success) {
                $output->writeln(sprintf(
                    "Coverage is above required threshold (%.2f%% > %.2f%%).",
                    $coverage_percentage,
                    $coverage_threshold
                ));
            } else {
                $output->writeln(sprintf(
                    "Coverage is below required threshold (%.2f%% < %.2f%%).",
                    $coverage_percentage,
                    $coverage_threshold
                ));

                return Command::FAILURE;
            }
        }
        return Command::SUCCESS;
    }

    /**
     * @throws ArgumentException
     */
    private function paths(mixed $argument): \Ds\Set
    {
        $paths = new \Ds\Set($argument);

        if ($paths->count() === 0) {
            throw new ArgumentException('At least one input path is required (preferably two).');
        }

        if (!Utilities::filesExist($paths)) {
            throw new ArgumentException("One or more of the given file paths couldn't be found.");
        }

        return $paths;
    }

    private function documents(\Ds\Set $paths): \Traversable
    {
        return $paths->map(function ($path) {
            $document = simplexml_load_file($path);
            if ($document === false) {
                throw new ArgumentException('Unable to parse one or more of the input files.');
            }
            return $document;
        });
    }

    private function mode($argument): string
    {
        if (!in_array($argument, ['inclusive', 'exclusive', 'additive'])) {
            throw new ArgumentException('Merge option must be one of: additive, exclusive or inclusive.');
        }

        return $argument;
    }
}
