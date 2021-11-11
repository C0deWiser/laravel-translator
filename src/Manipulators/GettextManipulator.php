<?php

namespace Codewiser\Polyglot\Manipulators;

use Codewiser\Polyglot\Collections\EntryCollection;
use Codewiser\Polyglot\Collections\FilesCollection;
use Codewiser\Polyglot\Contracts;
use Codewiser\Polyglot\FileLoader;
use Codewiser\Polyglot\Traits\Manipulator;
use Illuminate\Support\Str;
use Sepia\PoParser\Catalog\Catalog;
use Sepia\PoParser\Catalog\Entry;
use Sepia\PoParser\Catalog\Header;
use Sepia\PoParser\Parser;
use Sepia\PoParser\PoCompiler;
use Sepia\PoParser\SourceHandler\FileSystem;
use function collect;

class GettextManipulator implements Contracts\ManipulatorInterface
{
    use Manipulator;

    /**
     * StringsManipulator to work with passthroughs strings.
     *
     * @var StringsManipulator
     */
    protected StringsManipulator $stringsManipulator;

    /**
     * Translate these strings using Translator service.
     *
     * @var array
     */
    protected array $passthroughs = [];

    protected string $msginit = 'msginit';
    protected string $msgmerge = 'msgmerge';
    protected string $msgfmt = 'msgfmt';

    public function __construct(array $locales, FileLoader $loader, StringsManipulator $manipulator)
    {
        $this->locales = $locales;
        $this->loader = $loader;
        $this->stringsManipulator = $manipulator;

        $this->fs = $loader->filesystem();
        $this->storage = $loader->storage();
    }

    /**
     * Set array of strings, that should be translated by legacy service.
     *
     * @param array $passthroughs
     * @return $this
     */
    public function setPassthroughs(array $passthroughs): GettextManipulator
    {
        $this->passthroughs = $passthroughs;
        return $this;
    }

    /**
     * Compile .mo files.
     */
    public function compile()
    {
        foreach ($this->getLocaleListing() as $localeDir) {
            $locale = $this->fs->basename($localeDir);

            foreach ($this->getCategoryListing($locale) as $categoryDir) {
                $category = $this->fs->basename($categoryDir);

                foreach ($this->getPortableObjectListing($locale, $category) as $po) {
                    $text_domain = $this->fs->name($po);

                    $mo = $this->getMachineObject($locale, $category, $text_domain);
                    $this->runMsgFmt($po, $mo);
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function populate(string $template)
    {
        if (!$this->fs->exists($template)) {
            return;
        }

        // We should divide collected strings into two parts — legacy and gettext...
        // Then store legacy strings using StringCollector
        $passthroughs = $this->fs->dirname($template) . DIRECTORY_SEPARATOR . 'passthroughs_' . $this->fs->basename($template);
        $this->splitPortableObjectTemplate($template, $passthroughs, $this->passthroughs);

        if ($this->fs->exists($passthroughs)) {
            $this->getPassthroughsManipulator()->populate($passthroughs);
            $this->fs->delete($passthroughs);
        }

        $text_domain = $this->fs->name($template);
        $category = $this->fs->basename($this->fs->dirname($template));

        foreach ($this->getLocales() as $locale) {
            $po = $this->getPortableObject($locale, $category, $text_domain);

            if (!$this->fs->exists($po)) {

                if (!$this->fs->exists($this->fs->dirname($po))) {
                    $this->fs->makeDirectory($this->fs->dirname($po), 0777, true);
                }

                $this->runMsgInit($template, $po, $locale);
            } else {
                $this->runMsgMerge($template, $po);
            }
        }
    }

    public function getLocaleListing(): array
    {
        return $this->fs->glob($this->storage . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    }

    /**
     * Get existing categories for the locale.
     *
     * @param string $locale
     * @return array
     */
    public function getCategoryListing(string $locale): array
    {
        // Sanitize, if they send filename
        $locale = $this->fs->name($locale);

        return glob($this->storage . DIRECTORY_SEPARATOR .
            $locale . DIRECTORY_SEPARATOR . 'LC_*');
    }

    /**
     * Get existing .po files for the given locale and category.
     *
     * @param string $locale
     * @param string $category
     * @return array
     */
    public function getPortableObjectListing(string $locale, string $category): array
    {
        // Sanitize, if they send filename
        $category = $this->fs->name($category);

        return glob($this->storage . DIRECTORY_SEPARATOR .
            $locale . DIRECTORY_SEPARATOR .
            $category . DIRECTORY_SEPARATOR . '*.po');
    }

    /**
     * Get existing .mo files for the given locale and category.
     *
     * @param string $locale
     * @param string $category
     * @return array
     */
    public function getMachineObjectListing(string $locale, string $category): array
    {
        // Sanitize, if they send filename
        $category = $this->fs->name($category);

        return glob($this->storage . DIRECTORY_SEPARATOR .
            $locale . DIRECTORY_SEPARATOR .
            $category . DIRECTORY_SEPARATOR . '*.mo');
    }

    /**
     * Get the path to the .po file.
     *
     * @param string $locale
     * @param string $category
     * @param string $text_domain
     * @return string
     */
    public function getPortableObject(string $locale, string $category, string $text_domain): string
    {
        // Sanitize, if they send filename
        $locale = $this->fs->name($locale);
        $category = $this->fs->name($category);
        $text_domain = $this->fs->name($text_domain);

        return $this->storage . DIRECTORY_SEPARATOR .
            $locale . DIRECTORY_SEPARATOR .
            $category . DIRECTORY_SEPARATOR . $text_domain . '.po';
    }

    /**
     * Get the path to the .mo file.
     *
     * @param string $locale
     * @param string $category
     * @param string $text_domain
     * @return string
     */
    public function getMachineObject(string $locale, string $category, string $text_domain): string
    {
        // Sanitize, if they send filename
        $locale = $this->fs->name($locale);
        $category = $this->fs->name($category);
        $text_domain = $this->fs->name($text_domain);

        return $this->storage . DIRECTORY_SEPARATOR .
            $locale . DIRECTORY_SEPARATOR .
            $category . DIRECTORY_SEPARATOR . $text_domain . '.mo';
    }

    /**
     * Extract legacy strings to the new pot file.
     *
     * @param string $sourcePot
     * @param string $legacyPot
     * @param array $passthrougs
     * @return void
     */
    protected function splitPortableObjectTemplate(string $sourcePot, string $legacyPot, array $passthrougs): void
    {
        if ($this->fs->exists($legacyPot)) {
            $this->fs->delete($legacyPot);
        }
        $this->fs->copy($sourcePot, $legacyPot);

        try {
            $file = new FileSystem($sourcePot);
            $parser = new Parser($file);
            $catalog = $parser->parse();

            foreach ($catalog->getEntries() as $entry) {
                if (Str::startsWith($entry->getMsgId(), $passthrougs)) {
                    $catalog->removeEntry($entry->getMsgId(), $entry->getMsgCtxt());
                }
            }

            $file->save((new PoCompiler())->compile($catalog));
        } catch (\Exception $e) {
        }

        try {
            $file = new FileSystem($legacyPot);
            $parser = new Parser($file);
            $catalog = $parser->parse();

            foreach ($catalog->getEntries() as $entry) {
                if (!Str::startsWith($entry->getMsgId(), $passthrougs)) {
                    $catalog->removeEntry($entry->getMsgId(), $entry->getMsgCtxt());
                }
            }

            $file->save((new PoCompiler())->compile($catalog));
        } catch (\Exception $e) {
        }
    }

    public function get(string $locale, string $category, string $text_domain, string $msgid, string $context = null): ?Entry
    {
        try {
            return $this->readPortableObject($locale, $category, $text_domain)
                ->getEntry($msgid, $context ?: null);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function put(string $locale, string $category, string $text_domain, array $data)
    {
        $filename = $this->getPortableObject($locale, $category, $text_domain);

        $file = new FileSystem($filename);

        $catalog = $this->readPortableObject($locale, $category, $text_domain);

        $entry = $catalog->getEntry($data['msgid'], @$data['context'] ? $data['context'] : null);
        if (!$entry) {
            $exists = false;
            $entry = new Entry($data['msgid']);
            if (isset($data['msgid_plural'])) {
                $entry->setMsgIdPlural($data['msgid_plural']);
            }
        } else {
            $exists = true;
        }

        if (($exists && $entry->isPlural()) || (!$exists && isset($data['msgid_plural']))) {
            $entry->setMsgStrPlurals(
                collect($data['msgstr'])
                    ->map(function ($value) {
                        return (string)$value;
                    })
                    ->toArray()
            );
        } else {
            $entry->setMsgStr((string)$data['msgstr']);
        }

        $flags = $entry->getFlags();
        if (isset($data['fuzzy']) && $data['fuzzy']) {
            $flags[] = 'fuzzy';
        } else {
            $flags = array_diff($flags, ['fuzzy']);
        }
        $entry->setFlags(array_unique($flags));

        $entry->setTranslatorComments((array)$data['comment']);

        if (!$exists) {
            $catalog->addEntry($entry);
        }

        $file->save((new PoCompiler())->compile($catalog));
    }

    public function updateHeaders(string $locale, string $category, string $text_domain, array $headers)
    {
        $filename = $this->getPortableObject($locale, $category, $text_domain);

        if (file_exists($filename)) {
            $content = file_get_contents($filename);
            foreach ($headers as $key => $value) {
                $content = preg_replace(
                    '~^"' . $key . ':.*?"~mi',
                    '"' . $key . ': ' . $value . '\n"',
                    $content
                );
            }
            file_put_contents($filename, $content);
        }
    }

    protected function runMsgInit($pot, $po, $locale)
    {
        $command = $this->msginit . " --no-translator --no-wrap --input={$pot} --output-file={$po} --locale={$locale}";
        exec($command);
    }

    protected function runMsgMerge($pot, $po)
    {
        $command = $this->msgmerge . " --no-wrap --sort-output --update {$po} {$pot}";
        exec($command);
    }

    protected function runMsgFmt($po, $mo)
    {
        $command = $this->msgfmt . " --use-fuzzy --output-file={$mo} {$po}";
        exec($command);
    }

    /**
     * Set msginit executable.
     *
     * @param string $executable
     * @return $this
     */
    public function msginit(string $executable): GettextManipulator
    {
        $this->msginit = $executable;
        return $this;
    }

    /**
     * Set msgmerge executable.
     *
     * @param string $executable
     * @return $this
     */
    public function msgmerge(string $executable): GettextManipulator
    {
        $this->msgmerge = $executable;
        return $this;
    }

    /**
     * Set msgfmt executable.
     *
     * @param string $executable
     * @return $this
     */
    public function msgfmt(string $executable): GettextManipulator
    {
        $this->msgfmt = $executable;
        return $this;
    }

    protected function readPortableObject(string $locale, string $category, string $text_domain): Catalog
    {
        $filename = $this->getPortableObject($locale, $category, $text_domain);

        $parser = new Parser(new FileSystem($filename));

        return $parser->parse();
    }

    public function getHeader(string $locale, string $category, string $text_domain): ?Header
    {
        try {
            return $this->readPortableObject($locale, $category, $text_domain)
                ->getHeader();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getHeaders(string $locale, string $category, string $text_domain): array
    {
        try {
            $header = $this->getHeader($locale, $category, $text_domain);

            return collect($header->asArray())
                ->mapWithKeys(function (string $string) {
                    $values = explode(':', $string);
                    return [array_shift($values) => trim(implode(':', $values))];
                })
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getStrings(string $locale, string $category, string $text_domain): EntryCollection
    {
        try {
            return EntryCollection::make(
                $this->readPortableObject($locale, $category, $text_domain)
                    ->getEntries()
            );

        } catch (\Exception $e) {
            return new EntryCollection();
        }
    }

    public function getPassthroughsManipulator(): StringsManipulator
    {
        return $this->stringsManipulator;
    }

    public function all(string $locale = null): EntryCollection
    {
        $strings = new EntryCollection();

        $locales = $locale ? (array)$locale : $this->getLocales();

        foreach ($locales as $locale) {
            foreach ($this->getCategoryListing($locale) as $category) {
                foreach ($this->getPortableObjectListing($locale, $category) as $filename) {
                    $strings = $strings->merge(
                        $this->getStrings($locale, $category, $filename)
                    );
                }
            }
        }

        return $strings;
    }

    public function files(string $locale = null): FilesCollection
    {
        $locales = $locale ? (array)$locale : $this->getLocales();

        $files = new FilesCollection;

        foreach ($locales as $locale) {
            foreach ($this->getCategoryListing($locale) as $category) {
                $files = $files->merge(
                    $this->getPortableObjectListing($locale, $category)
                );
            }
        }

        return $files;
    }

    public function outputFiles(string $locale = null): FilesCollection
    {
        $locales = $locale ? (array)$locale : $this->getLocales();

        $files = new FilesCollection;

        foreach ($locales as $locale) {
            foreach ($this->getCategoryListing($locale) as $category) {
                $files = $files->merge(
                    $this->getMachineObjectListing($locale, $category)
                );
            }
        }

        return $files;
    }
}