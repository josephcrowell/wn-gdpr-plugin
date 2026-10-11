<?php

// Render the shipped Twig partials without starting Winter or requiring a database/theme.
require dirname(__DIR__, 5) . '/vendor/autoload.php';

use Twig\Compiler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TwigFilter;
use Twig\TwigFunction;

class FixturePartialNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $compiler->write('echo $this->env->render(')
            ->subcompile($this->getNode('name'))
            ->raw(', array_merge($context, ')
            ->subcompile($this->getNode('arguments'))
            ->raw('));' . PHP_EOL);
    }
}

class FixturePartialParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();
        $name = $this->parser->getExpressionParser()->parseExpression();
        $arguments = new ArrayExpression([], $token->getLine());
        while (!$stream->test(Token::BLOCK_END_TYPE)) {
            $key = $stream->expect(Token::NAME_TYPE)->getValue();
            $stream->expect(Token::OPERATOR_TYPE, '=');
            $arguments->addElement(
                $this->parser->getExpressionParser()->parseExpression(),
                new ConstantExpression($key, $token->getLine())
            );
        }
        $stream->expect(Token::BLOCK_END_TYPE);

        return new FixturePartialNode(['name' => $name, 'arguments' => $arguments], [], $token->getLine());
    }

    public function getTag(): string
    {
        return 'partial';
    }
}

class FixtureComponent
{
    public $cookieGroups;
    public $consent;
    public $includeJs = true;
    public $consentExpiry = 12;
    public $hide = false;
    public $cookieManagerPage = 'cookies';
    public $updatePartial = 'gdpr';
    public $updateSelector = '#gdpr-reload';
    public $bgCookies = false;
    public $colorScheme = 'blue';
    public $selectedGroupIds = [1, 2];
    private $alias;

    public function __construct(string $alias)
    {
        $this->alias = $alias;
    }

    public function __toString(): string
    {
        return $this->alias;
    }
}

$root = dirname(__DIR__, 2);
$templates = [];
foreach (glob($root . '/components/{cookiebanner,cookiemanager,cookiebannerwide}/*.htm', GLOB_BRACE) as $file) {
    $relative = substr($file, strlen($root) + 1);
    if (getenv('GDPR_LEGACY_REF') && basename(dirname($file)) === 'cookiebannerwide') continue;
    $content = getenv('GDPR_LEGACY_REF')
        ? shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg(getenv('GDPR_LEGACY_REF') . ':' . $relative))
        : file_get_contents($file);
    $name = basename(dirname($file)) . '::' . basename($file);
    $templates[$name] = str_replace(['{% put scripts %}', '{% endput %}'], '', $content);
    $templates[substr($name, 0, -4)] = $templates[$name];
}

$translations = [
    'cookie_banner.message' => 'This website uses cookies to improve your experience. You can choose which cookies you allow.',
    'cookie_banner.accept' => 'Accept cookies',
    'cookie_banner.decline' => 'Decline cookies',
    'cookie_banner.advanced_settings' => 'Advanced settings',
    'cookie_manager.save' => 'Save settings',
];
$twig = new Environment(new ArrayLoader($templates));
$twig->addTokenParser(new FixturePartialParser());
$wideTranslations = (require $root . '/lang/en/lang.php')['components']['bannerwide'];
$twig->addFilter(new TwigFilter('trans', function ($key) use ($translations, $wideTranslations) {
    $key = str_replace('josephcrowell.gdpr::lang.', '', $key);
    if (str_starts_with($key, 'cookie_manager.expiry.')) {
        return substr($key, strlen('cookie_manager.expiry.')) . ' months';
    }
    if (str_starts_with($key, 'components.bannerwide.')) {
        $value = $wideTranslations;
        foreach (explode('.', substr($key, strlen('components.bannerwide.'))) as $part) {
            $value = $value[$part] ?? $key;
        }
        return $value;
    }
    return $translations[$key] ?? ucfirst(str_replace('_', ' ', substr($key, strrpos($key, '.') + 1)));
}));
$twig->addFilter(new TwigFilter('page', fn () => '/cookies'));
$twig->addFunction(new TwigFunction('form_open', fn () => '<form method="post">', ['is_safe' => ['html']]));
$twig->addFunction(new TwigFunction('form_close', fn () => '</form>', ['is_safe' => ['html']]));
$twig->addFunction(new TwigFunction('form_token', fn () => '<input type="hidden" name="_token" value="fixture-csrf">', ['is_safe' => ['html']]));

$groups = [
    [
        'id' => 1, 'name' => 'Essential cookies', 'description' => '<p>Required for this website to work.</p>', 'required' => true,
        'cookies' => [[
            'id' => 1, 'code' => 'session', 'name' => 'Session cookie', 'group' => ['required' => true],
            'description' => '<p>Keeps your session and preferences secure.</p>', 'provider' => 'This website',
            'levels' => [],
            'item_details' => [['detail_name' => 'session', 'detail_expiry' => 'Session', 'detail_type' => 'HTTP']],
            'contact_details_dpo' => '<p>Privacy team</p><p>privacy@example.com</p>',
            'contact_links_dpo' => [['contact_link_name' => 'Privacy policy', 'contact_link_href' => '/privacy']],
        ]],
    ],
    [
        'id' => 2, 'name' => 'Analytics', 'description' => '<p>Helps us understand how the site is used.</p>', 'required' => false,
        'cookies' => [[
            'id' => 2, 'code' => 'analytics', 'name' => 'Website analytics', 'group' => ['required' => false],
            'description' => '<p>Collects anonymous usage statistics.</p>', 'provider' => 'Analytics provider',
            'levels' => [
                ['level_name' => 'Basic statistics', 'level_description' => '<p>Anonymous page views.</p>'],
                ['level_name' => 'Detailed statistics', 'level_description' => '<p>Anonymous visits and interactions.</p>'],
            ],
            'item_details' => [
                ['detail_name' => 'analytics_id', 'detail_expiry' => '12 months', 'detail_type' => 'HTTP'],
                ['detail_name' => 'analytics_visit', 'detail_expiry' => '24 hours', 'detail_type' => 'HTTP'],
            ],
            'contact_details_dpo' => '<p>Analytics privacy team</p>', 'contact_links_dpo' => [],
        ]],
    ],
];

$componentName = $argv[1] ?? 'cookiebanner';
$alias = $argv[5] ?? $componentName;
if ($alias !== $componentName) {
    foreach ($templates as $name => $content) {
        if (str_starts_with($name, $componentName . '::')) {
            $twig->getLoader()->setTemplate($alias . substr($name, strlen($componentName)), $content);
        }
    }
}
$component = new FixtureComponent($alias);
if ($componentName === 'cookiebannerwide') {
    $component->colorScheme = $argv[2] ?? 'blue';
    $component->bgCookies = ($argv[3] ?? '0') === '1';
    $component->selectedGroupIds = ($argv[4] ?? '') === 'inactive' ? [1] : [1, 2];
    $groups[] = [
        'id' => 3, 'name' => 'Personalisation', 'description' => 'Optional cookies', 'required' => false,
        'cookies' => [['id' => 3, 'name' => 'Disabled preset', 'code' => 'personalisation']],
    ];
}
$component->cookieGroups = $groups;
$component->consent = ['session' => 0, 'analytics' => 0];
echo $twig->render($componentName . '::default', ['__SELF__' => $component]);
