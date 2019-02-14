<?php declare(strict_types=1);

namespace Symplify\BetterPhpDocParser;

use Nette\Utils\Strings;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Symplify\BetterPhpDocParser\Attributes\Ast\PhpDoc\AttributeAwarePhpDocNode;
use Symplify\BetterPhpDocParser\Attributes\Ast\PhpDoc\Type\AttributeAwareIdentifierTypeNode;
use Symplify\BetterPhpDocParser\NodeDecorator\StringsTypePhpDocNodeDecorator;
use Symplify\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;

final class PhpDocModifier
{
    /**
     * @var StringsTypePhpDocNodeDecorator
     */
    private $stringsTypePhpDocNodeDecorator;

    public function __construct(StringsTypePhpDocNodeDecorator $stringsTypePhpDocNodeDecorator)
    {
        $this->stringsTypePhpDocNodeDecorator = $stringsTypePhpDocNodeDecorator;
    }

    public function removeTagByName(PhpDocInfo $phpDocInfo, string $tagName): void
    {
        $phpDocNode = $phpDocInfo->getPhpDocNode();

        $tagName = $this->normalizeFromLeft($tagName, '@');
        $phpDocTagNodes = $phpDocNode->getTagsByName($tagName);

        foreach ($phpDocTagNodes as $phpDocTagNode) {
            $this->removeTagFromPhpDocNode($phpDocNode, $phpDocTagNode);
        }
    }

    public function removeTagByNameAndContent(PhpDocInfo $phpDocInfo, string $tagName, string $tagContent): void
    {
        $phpDocNode = $phpDocInfo->getPhpDocNode();

        $tagName = $this->normalizeFromLeft($tagName, '@');
        $phpDocTagNodes = $phpDocNode->getTagsByName($tagName);

        foreach ($phpDocTagNodes as $phpDocTagNode) {
            if (! $phpDocTagNode instanceof PhpDocTagNode) {
                continue;
            }

            if (! $phpDocTagNode->value instanceof PhpDocTagValueNode) {
                continue;
            }

            // e.g. @method someMethod(), only matching content is enough, due to real case usability
            if (Strings::contains((string) $phpDocTagNode->value, $tagContent)) {
                $this->removeTagFromPhpDocNode($phpDocNode, $phpDocTagNode);
            }
        }
    }

    public function removeParamTagByParameter(PhpDocInfo $phpDocInfo, string $parameterName): void
    {
        $phpDocNode = $phpDocInfo->getPhpDocNode();

        /** @var PhpDocTagNode[] $phpDocTagNodes */
        $phpDocTagNodes = $phpDocNode->getTagsByName('@param');

        foreach ($phpDocTagNodes as $phpDocTagNode) {
            /** @var ParamTagValueNode|InvalidTagValueNode $paramTagValueNode */
            $paramTagValueNode = $phpDocTagNode->value;

            $parameterName = '$' . ltrim($parameterName, '$');

            // process invalid tag values
            if ($paramTagValueNode instanceof InvalidTagValueNode) {
                if ($paramTagValueNode->value === $parameterName) {
                    $this->removeTagFromPhpDocNode($phpDocNode, $phpDocTagNode);
                }
                // process normal tag
            } elseif ($paramTagValueNode->parameterName === $parameterName) {
                $this->removeTagFromPhpDocNode($phpDocNode, $phpDocTagNode);
            }
        }
    }

    public function removeReturnTagFromPhpDocNode(PhpDocNode $phpDocNode): void
    {
        foreach ($phpDocNode->getReturnTagValues() as $returnTagValue) {
            $this->removeTagFromPhpDocNode($phpDocNode, $returnTagValue);
        }
    }

    /**
     * @param PhpDocTagNode|PhpDocTagValueNode $phpDocTagOrPhpDocTagValueNode
     */
    public function removeTagFromPhpDocNode(PhpDocNode $phpDocNode, $phpDocTagOrPhpDocTagValueNode): void
    {
        // remove specific tag
        foreach ($phpDocNode->children as $key => $phpDocChildNode) {
            if ($phpDocChildNode === $phpDocTagOrPhpDocTagValueNode) {
                unset($phpDocNode->children[$key]);
                return;
            }
        }

        // or by type
        foreach ($phpDocNode->children as $key => $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if ($phpDocChildNode->value === $phpDocTagOrPhpDocTagValueNode) {
                unset($phpDocNode->children[$key]);
            }
        }
    }

    public function replaceTagByAnother(PhpDocNode $phpDocNode, string $oldTag, string $newTag): void
    {
        $oldTag = $this->normalizeFromLeft($oldTag, '@');
        $newTag = $this->normalizeFromLeft($newTag, '@');

        foreach ($phpDocNode->children as $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if ($phpDocChildNode->name === $oldTag) {
                $phpDocChildNode->name = $newTag;
            }
        }
    }

    public function replacePhpDocTypeByAnother(
        AttributeAwarePhpDocNode $phpDocNode,
        string $oldType,
        string $newType
    ): void {
        foreach ($phpDocNode->children as $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if (! $this->isTagValueNodeWithType($phpDocChildNode)) {
                continue;
            }

            /** @var VarTagValueNode|ParamTagValueNode|ReturnTagValueNode $tagValueNode */
            $tagValueNode = $phpDocChildNode->value;
            $phpDocChildNode->value->type = $this->replaceTypeNode($tagValueNode->type, $oldType, $newType);

            $this->stringsTypePhpDocNodeDecorator->decorate($phpDocNode);
        }
    }

    private function isTagValueNodeWithType(PhpDocTagNode $phpDocTagNode): bool
    {
        return $phpDocTagNode->value instanceof ParamTagValueNode ||
            $phpDocTagNode->value instanceof VarTagValueNode ||
            $phpDocTagNode->value instanceof ReturnTagValueNode;
    }

    private function replaceTypeNode(TypeNode $typeNode, string $oldType, string $newType): TypeNode
    {
        if ($typeNode instanceof AttributeAwareIdentifierTypeNode) {
            if (is_a($typeNode->name, $oldType, true) || ltrim($typeNode->name, '\\') === $oldType) {
                $newType = $this->makeTypeFqn($newType);

                return new AttributeAwareIdentifierTypeNode($newType);
            }
        }

        if ($typeNode instanceof UnionTypeNode) {
            foreach ($typeNode->types as $key => $subTypeNode) {
                $typeNode->types[$key] = $this->replaceTypeNode($subTypeNode, $oldType, $newType);
            }
        }

        if ($typeNode instanceof ArrayTypeNode) {
            $typeNode->type = $this->replaceTypeNode($typeNode->type, $oldType, $newType);

            return $typeNode;
        }

        return $typeNode;
    }

    private function makeTypeFqn(string $type): string
    {
        if (Strings::contains($type, '\\')) {
            $type = $this->normalizeFromLeft($type, '\\');
        }

        return $type;
    }

    private function normalizeFromLeft(string $value, string $char): string
    {
        return $char . ltrim($value, $char);
    }
}
