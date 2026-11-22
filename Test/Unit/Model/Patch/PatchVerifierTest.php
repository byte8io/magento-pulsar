<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Test\Unit\Model\Patch;

use Byte8\Pulsar\Model\Patch\PatchVerifier;
use Byte8\Pulsar\Model\Patch\UnifiedDiffParser;
use PHPUnit\Framework\TestCase;

class PatchVerifierTest extends TestCase
{
    private string $root;
    private PatchVerifier $verifier;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pulsar-verifier-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor/acme/widget/Model', 0777, true);
        mkdir($this->root . '/vendor/bin', 0777, true);
        $this->verifier = new PatchVerifier(new UnifiedDiffParser());
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * Composer patches are written relative to their package; the verifier must
     * resolve them against vendor/<package>, and report the resolved target so
     * the collector can relate patches touching the same live file.
     */
    public function testPackageRelativePathResolvesAgainstVendorPackage(): void
    {
        $target = $this->root . '/vendor/acme/widget/Model/Config.php';
        file_put_contents($target, "<?php\nclass Config {\n    public function patchedMethodName() {}\n}\n");

        $patch = <<<'DIFF'
            diff --git a/Model/Config.php b/Model/Config.php
            --- a/Model/Config.php
            +++ b/Model/Config.php
            @@ -1,3 +1,3 @@
             class Config {
            -    public function originalMethodName() {}
            +    public function patchedMethodName() {}
             }
            DIFF;

        $withPackage = $this->verifier->verify($patch, [$this->root, $this->root . '/vendor/acme/widget']);
        self::assertSame(PatchVerifier::RESULT_APPLIED, $withPackage['result']);
        self::assertSame($target, $withPackage['targets']['Model/Config.php']);

        // Same file scanned from a patch directory with no package context:
        // nothing under the root matches, which is what the collector then
        // classifies as "unreferenced".
        $withoutPackage = $this->verifier->verify($patch, [$this->root]);
        self::assertSame(PatchVerifier::RESULT_TARGET_MISSING, $withoutPackage['result']);
        self::assertNull($withoutPackage['targets']['Model/Config.php']);
    }

    /**
     * Adobe's cumulative monthly patches rewrite the same framework files. The
     * older month's hunks are gone (partial / not applied) but the newer month
     * verifies applied on that same target — the collector marks the older one
     * superseded using the targets both reports expose.
     */
    public function testSupersededMonthlyPatchesShareAResolvedTarget(): void
    {
        $target = $this->root . '/vendor/acme/widget/Model/Escaper.php';
        file_put_contents(
            $target,
            "<?php\nclass Escaper {\n    private const ALLOWED_TAGS_OCTOBER_REVISION = ['b'];\n}\n"
        );

        $july = <<<'DIFF'
            --- a/vendor/acme/widget/Model/Escaper.php
            +++ b/vendor/acme/widget/Model/Escaper.php
            @@ -1,3 +1,3 @@
             class Escaper {
            -    private const ALLOWED_TAGS_ORIGINAL_LIST = ['b'];
            +    private const ALLOWED_TAGS_JULY_REVISION_LIST = ['b'];
             }
            DIFF;
        $october = <<<'DIFF'
            --- a/vendor/acme/widget/Model/Escaper.php
            +++ b/vendor/acme/widget/Model/Escaper.php
            @@ -1,3 +1,3 @@
             class Escaper {
            -    private const ALLOWED_TAGS_JULY_REVISION_LIST = ['b'];
            +    private const ALLOWED_TAGS_OCTOBER_REVISION = ['b'];
             }
            DIFF;

        $julyResult = $this->verifier->verify($july, [$this->root]);
        $octoberResult = $this->verifier->verify($october, [$this->root]);

        self::assertSame(PatchVerifier::RESULT_NOT_APPLIED, $julyResult['result']);
        self::assertSame(PatchVerifier::RESULT_APPLIED, $octoberResult['result']);
        self::assertSame(
            $octoberResult['targets']['vendor/acme/widget/Model/Escaper.php'],
            $julyResult['targets']['vendor/acme/widget/Model/Escaper.php']
        );
    }

    public function testComposerBinProxiesAreIgnored(): void
    {
        file_put_contents($this->root . '/vendor/bin/patch-status', "#!/usr/bin/env php\nproxy\n");
        $target = $this->root . '/vendor/acme/widget/Model/Config.php';
        file_put_contents($target, "<?php\nclass Config {\n    public function patchedMethodName() {}\n}\n");

        $patch = <<<'DIFF'
            --- a/vendor/bin/patch-status
            +++ b/vendor/bin/patch-status
            @@ -1,2 +1,2 @@
             #!/usr/bin/env php
            -proxy-generated-by-composer-original
            +proxy-generated-by-composer-revised
            --- a/vendor/acme/widget/Model/Config.php
            +++ b/vendor/acme/widget/Model/Config.php
            @@ -1,3 +1,3 @@
             class Config {
            -    public function originalMethodName() {}
            +    public function patchedMethodName() {}
             }
            DIFF;

        $r = $this->verifier->verify($patch, [$this->root]);

        self::assertSame(PatchVerifier::RESULT_APPLIED, $r['result']);
        self::assertArrayNotHasKey('vendor/bin/patch-status', $r['files']);
    }
}
