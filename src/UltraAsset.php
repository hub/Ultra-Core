<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 24-06-2018
 */

namespace Hub\UltraCore;

class UltraAsset
{
    /** @var bool */
    private $isMergedAsset = false;

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

    /** @var bool */
    private $isApproved = false;

    /** @var bool */
    private $isFeatured = false;

    /** @var int */
    private $authorityUserId;

    /** @var UltraAssetWeighting[] */
    private $weightings;

    /**
     * UltraAsset constructor.
     * @param int $id
     * @param string $weightingHash
     * @param string $title
     * @param string $tickerSymbol
     * @param float $numAssets
     * @param string $backgroundImage
     * @param bool $isApproved
     * @param bool $isFeatured
     * @param int $authorityUserId
     * @param array $weightings
     */
    public function __construct($id, $weightingHash, $title, $tickerSymbol, $numAssets, $backgroundImage, $isApproved, $isFeatured, $authorityUserId, array $weightings)
    {
        $this->id = $id;
        $this->weightingHash = $weightingHash;
        $this->setTitle($title);
        $this->setTickerSymbol($tickerSymbol);
        $this->incrementAssetsQuantity(floatval($numAssets));
        $this->backgroundImage = $backgroundImage;
        $this->isApproved = boolval($isApproved);
        $this->isFeatured = boolval($isFeatured);
        $this->authorityUserId = intval($authorityUserId);
        $this->weightings = $weightings;
    }

    /**
     * @return bool
     */
    public function isMergedAsset()
    {
        return $this->isMergedAsset;
    }

    public function markAsMergedAsset()
    {
        $this->isMergedAsset = true;
    }

    /**
     * @return int
     */
    public function id()
    {
        return $this->id;
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
     * @return string
     */
    public function backgroundImage()
    {
        return $this->backgroundImage;
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
     * @return UltraAssetWeighting[]
     */
    public function weightings()
    {
        return $this->weightings;
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
            'title' => $this->title(),
            'tickerSymbol' => $this->tickerSymbol(),
            'numAssets' => $this->numAssets(),
            'backgroundImage' => $this->backgroundImage(),
            'isApproved' => $this->isApproved(),
            'isFeatured' => $this->isFeatured(),
            'authorityUserId' => $this->authorityUserId(),
            'weightings' => $this->weightings(),
        );
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
