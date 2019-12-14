<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 24-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\Currency;

class UltraAsset
{
    /** @var int */
    private $id;

    /** @var string */
    private $weightingHash;

    /** @var string */
    private $title;

    /** @var string */
    private $tickerSymbol;

    /** @var float */
    private $numAssets = 0;

    /** @var string */
    private $backgroundImage;

    /** @var string */
    private $iconImage;

    /** @var bool */
    private $isApproved = false;

    /** @var bool */
    private $isFeatured = false;

    /** @var int */
    private $authorityUserId;

    /** @var string */
    private $weightingType;

    /** @var UltraAssetWeighting[] */
    private $weightings;

    /** @var string */
    private $submissionDate;

    /**
     * UltraAsset constructor.
     *
     * @param int                   $id
     * @param string                $weightingHash
     * @param string                $title
     * @param string                $category
     * @param string                $tickerSymbol
     * @param float                 $numAssets
     * @param string                $backgroundImage
     * @param string                $iconImage
     * @param bool                  $isApproved
     * @param bool                  $isFeatured
     * @param int                   $authorityUserId
     * @param string                $weightingType
     * @param UltraAssetWeighting[] $weightings
     * @param string                $submissionDate
     */
    public function __construct(
        $id,
        $weightingHash,
        $title,
        $category,
        $tickerSymbol,
        $numAssets,
        $backgroundImage,
        $iconImage,
        $isApproved,
        $isFeatured,
        $authorityUserId,
        $weightingType,
        array $weightings,
        $submissionDate
    ) {
        $this->id = $id;
        $this->weightingHash = $weightingHash;
        $this->setTitle($title);
        $this->category = $category;
        $this->setTickerSymbol($tickerSymbol);
        $this->incrementAssetsQuantity(floatval($numAssets));
        $this->backgroundImage = $backgroundImage;
        $this->iconImage = $iconImage;
        $this->isApproved = boolval($isApproved);
        $this->isFeatured = boolval($isFeatured);
        $this->authorityUserId = intval($authorityUserId);
        $this->weightingType = $weightingType;
        $this->weightings = $weightings;
        $this->submissionDate = $submissionDate;
    }

    /**
     * @return int
     */
    public function id()
    {
        return intval($this->id);
    }

    /**
     * @return string
     */
    public function weightingHash()
    {
        return $this->weightingHash;
    }

    /**
     * @return string
     */
    public function uniqueHash()
    {
        return md5(sprintf('%s:%s', $this->id, $this->weightingHash));
    }

    /**
     * @return string
     */
    public function title()
    {
        return $this->title;
    }

    /**
     * @param string $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @return string
     */
    public function category()
    {
        return $this->category;
    }

    /**
     * @return string
     */
    public function tickerSymbol()
    {
        return $this->tickerSymbol;
    }

    /**
     * @param string $tickerSymbol
     */
    public function setTickerSymbol($tickerSymbol)
    {
        $this->tickerSymbol = $tickerSymbol;
    }

    /**
     * @return float
     */
    public function numAssets()
    {
        return $this->numAssets;
    }

    /**
     * @param float $incrementValue
     */
    public function incrementAssetsQuantity($incrementValue)
    {
        $this->numAssets = $this->numAssets() + floatval($incrementValue);
    }

    /**
     * @param bool $absolutePath
     *
     * @return string
     */
    public function backgroundImage($absolutePath = false)
    {
        $image = empty($this->backgroundImage) ? 'ultra-asset-uploads/default-ultra-asset-background.png' : $this->backgroundImage;
        if (!$absolutePath) {
            return $image;
        }

        if (strpos($image, 'http') === false) {
            return sprintf('https://s3.amazonaws.com/%s', $image);
        }

        return $image;
    }

    /**
     * @param bool $absolutePath
     *
     * @return string
     */
    public function iconImage($absolutePath = false)
    {
        $image = empty($this->iconImage) ? 'ultra-asset-uploads/default-ultra-asset-icon.png' : $this->iconImage;
        if (!$absolutePath) {
            return $image;
        }

        if (strpos($image, 'http') === false) {
            return sprintf('https://s3.amazonaws.com/%s', $image);
        }

        return $image;
    }

    /**
     * @return bool
     */
    public function isApproved()
    {
        return $this->isApproved;
    }

    /**
     * @return bool
     */
    public function isFeatured()
    {
        return $this->isFeatured;
    }

    /**
     * @return int
     */
    public function authorityUserId()
    {
        return $this->authorityUserId;
    }

    /**
     * @return string
     */
    public function weightingType()
    {
        return $this->weightingType;
    }

    /**
     * @return UltraAssetWeighting[]
     */
    public function weightings()
    {
        return $this->weightings;
    }

    /**
     * @return bool
     */
    public function isComposedWithOtherCurrencies()
    {
        return $this->weightingType() === UltraAssetsRepository::TYPE_CURRENCY_COMBO;
    }

    /**
     * @return string
     */
    public function weightingsToString()
    {
        $weightings = array();
        foreach ($this->weightings as $weighting) {
            $weightings[] = $weighting->toArray();
        }

        return json_encode($weightings);
    }

    /**
     * @param string $format
     *
     * @return string
     */
    public function submissionDate($format = 'Y-m-d H:i:s')
    {
        return date($format, strtotime($this->submissionDate));
    }

    /**
     * @param UltraAssetWeighting[] $weightings
     */
    public function setWeightings(array $weightings)
    {
        $this->weightings = $weightings;
    }

    /**
     * @return bool
     */
    public function isWithOneWeighting()
    {
        return count($this->weightings()) === 1;
    }

    /**
     * @param int $percentage
     *
     * @return UltraAssetWeighting|null
     */
    public function getAssetWeightingByPercentage($percentage = 100)
    {
        foreach ($this->weightings() as $weighting) {
            if ($weighting->percentage() === $percentage) {
                return $weighting;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'id' => $this->id(),
            'weightingHash' => $this->weightingHash(),
            'unique_hash' => $this->uniqueHash(),
            'title' => $this->title(),
            'category' => $this->category(),
            'tickerSymbol' => $this->tickerSymbol(),
            'numAssets' => $this->numAssets(),
            'backgroundImage' => $this->backgroundImage(true),
            'iconImage' => $this->iconImage(true),
            'isApproved' => $this->isApproved(),
            'isFeatured' => $this->isFeatured(),
            'authorityUserId' => $this->authorityUserId(),
            'weightings' => $this->weightings(),
            'submissionDate' => $this->submissionDate(),
        );
    }

    /**
     * @return Currency
     */
    public function getCurrency()
    {
        return Currency::custom($this->tickerSymbol());
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $asArray = $this->toArray();
        $asArray['weightings'] = $this->weightingsToString();
        return json_encode($asArray);
    }
}
