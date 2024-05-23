<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

use Contao\StringUtil;

class ColumnDefinition implements \Serializable
{
    protected ?string $span = null;
    protected ?string $offset = null;
    protected ?string $order = null;
    protected ?string $verticalAlign = null;
    protected ?string $customClasses = null;
    protected ?string $reset = '';
    protected ?string $insideClass = null;

    public const RESET_NONE = '';
    public const RESET_ALL = '1';
    public const RESET_SIZE = '2';
    protected const RESET = [
        self::RESET_NONE,
        self::RESET_ALL,
        self::RESET_SIZE,
    ];

    public function getSpan(): ?string
    {
        return $this->span;
    }

    public function setSpan(?string $span): self
    {
        $this->span = $span;
        return $this;
    }

    public function getOffset(): ?string
    {
        return $this->offset;
    }

    public function setOffset(?string $offset): self
    {
        $this->offset = \preg_replace('/\D+/', '', $offset);
        return $this;
    }

    public function getOrder(): ?string
    {
        return $this->order;
    }

    public function setOrder(?string $order): self
    {
        $this->order = \preg_replace('/\D+/', '', $order);
        return $this;
    }

    public function getVerticalAlign(): ?string
    {
        return $this->verticalAlign;
    }

    public function setVerticalAlign(string $verticalAlign): self
    {
        if (!in_array($verticalAlign, ['start', 'center', 'end'])) {
            throw new \InvalidArgumentException('Invalid vertical align value.');
        }
        $this->verticalAlign = $verticalAlign;
        return $this;
    }

    public function getCustomClasses(): ?string
    {
        return $this->customClasses;
    }

    public function setCustomClasses(string $customClasses): self
    {
        $this->customClasses = $customClasses;
        return $this;
    }

    public function getReset(): ?string
    {
        return $this->reset;
    }

    public function setReset(string $reset): self
    {
        if (!in_array($reset, self::RESET)) {
            throw new \InvalidArgumentException('Invalid reset value.');
        }
        $this->reset = $reset;
        return $this;
    }

    public function getInsideClass(): ?string
    {
        return $this->insideClass;
    }

    public function setInsideClass(?string $insideClass): self
    {
        $this->insideClass = $insideClass;
        return $this;
    }

    public function asArray(): array
    {
        return [
            'width'  => (string) $this->getSpan(),
            'offset' => (string) $this->getOffset(),
            'order'  => (string) $this->getOrder(),
            'align'  => (string) $this->getVerticalAlign(),
            'class'  => (string) $this->getCustomClasses(),
            'reset'  => (string) $this->getReset(),
        ];
    }

    public function serialize(): ?string
    {
        return serialize($this->asArray());
    }

    public function unserialize($data): void
    {
        $arr = StringUtil::deserialize($data);
        $this->setSpan($arr['width'] ?? null);
        $this->setOffset(\str_replace('offset-', '', $arr['offset'] ?? null));
        $this->setOrder(\str_replace('order-', '', $arr['order'] ?? null));
        $this->setVerticalAlign($arr['align'] ?? null);
        $this->setCustomClasses($arr['class'] ?? null);
        $this->setReset($arr['reset'] ?? null);
    }

    public static function create(): self
    {
        return new self();
    }
}