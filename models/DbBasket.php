<?php

namespace andreykluev\shopbasket\models;

use Yii;
use yii\db\ActiveRecord;
use yii\helpers\Json;
use yii\web\HttpException;

use andreykluev\shopbasket\BasketInterface;

/**
 * Class UserProduct
 * @package andreykluev\shopbasket\models
 *
 * @property string  $storage       id хранилища
 * @property integer $id_user       id пользователя
 * @property integer $id_product    id товара
 * @property string  $hash_product  Уникальный Хэш товара
 * @property float   $price         Цена товара
 * @property integer $count         Количество
 * @property array   $params        Дополнительные параметры
 * @property array   $created_at    Дата добавления товара в корзину
 * @property array   $updated_at    Дата изменения товара
 */
class DbBasket extends ActiveRecord implements BasketInterface
{
    /**
     * Товары корзины
     */
    public $basketProducts;

    /**
     * id пользователя, с чьей корзиной работаем
     */
    public $idUser;

    /**
     * Компонент от которого обращаемся
     */
    public $owner;

    /**
     * Определяет название таблицы в БД
     */
    public static function tableName()
    {
        return '{{%user_basket}}';
    }

    /**
     * Проверяет, присутствует ли товар в корзине пользователя
     * @param $hash - уникальный Хэш товара
     * @return boolean
     */
    public function isProductInBasket($hash)
    {
        foreach($this->owner->cache as $item) {
            if ($item['hash_product'] == $hash) return true;
        }

        return false;

//        return (bool)$this->find()
//            ->where([
//                'id_user' => $this->idUser,
//                'hash_product' => $hash
//            ])
//            ->count();
    }

    /**
     * Добавляет товар в корзину
     * @param $hash - уникальный Хэш товара
     * @param $pid - id товара
     * @param $price - цена товара
     * @param $params - дополнительные параметры товара
     * @param $count - количество
     * @return array
     * @throws HttpException
     */
    public function insertProduct($hash, $pid, $price, $params=[], $count=1)
    {
        // Если этот товар еще не в корзине
        if(!$this->isProductInBasket($hash)) {
            $basketProduct = new static;
            $basketProduct->id_user = $this->idUser;
            $basketProduct->storage = $this->owner->storageName;
            $basketProduct->id_product = $pid;
            $basketProduct->hash_product = $hash;
            $basketProduct->count = $count;
            $basketProduct->price = $price;
            $basketProduct->params = Json::encode($params);
            $basketProduct->created_at = time();
            $basketProduct->updated_at = time();

            $basketProduct->save();
        } else {
            $basketProduct = $this->findOne([
                'id_user' => $this->idUser,
                'hash_product' => $hash,
                'storage' => $this->owner->storageName
            ]);

            // Если модель не найдена, генерим Exception
            if(is_null($basketProduct)) {
                throw new HttpException(404, 'Model not found');
            }

            // Если кол-во == 0, то удаляем из корзины
            if(0<$count) {
                $basketProduct->count = $count;
                $basketProduct->price = $price;
                $basketProduct->save();
            } else {
                $basketProduct->delete();
            }
        }

        // После изменения товара в корзине, нужно обновить наш кеш
        $this->owner->cache = $this->getBasketProducts();

        return [
            'global' => [
                'count' => Yii::$app->formatter->asInteger($this->getBasketCount()),
                'total' => Yii::$app->formatter->asInteger($this->getBasketTotal()),
                'cost'  => Yii::$app->formatter->asCurrency($this->getBasketCost()),
            ],
            'current' => [
                'price' => Yii::$app->formatter->asCurrency($price),
                'count' => $count,
                'cost' => Yii::$app->formatter->asCurrency($price*$count),
            ],
            'result' => $this->isProductInBasket($hash)
        ];
    }

    /**
     * Возвращает список товаров в корзине
     * @return array
     */
    public function getBasketProducts()
    {
        return array_map(
            function($item) {
                $item['params'] = Json::decode($item['params']);
                return $item;
            },
            $this->find()
            ->where([
                'id_user' => $this->idUser,
                'storage' => $this->owner->storageName
            ])
            ->asArray()
            ->all()
        );
    }

    /**
     * @param $hash
     * @return mixed
     */
    public function getProductById($hash)
    {
        return $this->find()
            ->where([
                'id_user' => $this->idUser,
                'hash_product' => $hash
            ])
            ->asArray()
            ->one();
    }

    /**
     * Очищает корзину
     */
    public function eraseBasket()
    {
        $this->deleteAll([
            'id_user' => $this->idUser,
            'storage' => $this->owner->storageName
        ]);
    }

    /**
     * Возвращает количество наименований товара в корзине
     * @return int
     */
    public function getBasketCount()
    {
        return $this->find()
            ->where([
                'id_user' => $this->idUser,
                'storage' => $this->owner->storageName
            ])
            ->count();
    }

    /**
     * Возвращает количество единиц товаров в корзине
     * @return int
     */
    public function getBasketTotal()
    {
        $total = $this->find()
            ->where([
                'id_user' => $this->idUser,
                'storage' => $this->owner->storageName
            ])
            ->sum('count');

        return ($total) ? $total : 0;
    }

    /**
     * Возвращает сумму товаров в корзине
     * @return float
     */
    public function getBasketCost()
    {
        $cost = $this->find()
            ->where([
                'id_user' => $this->idUser,
                'storage' => $this->owner->storageName
            ])
            ->sum('price*count');

        return ($cost) ? $cost : 0;
    }

    /**
     * Сливает корзины из сессии и из БД
     */
    public function merge()
    {
        $sessionProducts = Yii::$app->session->get($this->owner->storageName, []);

        // Сливаем корзины из сессии и из БД в соответствии с выбранным методом
        switch($this->owner->mergeMethod) {
            // корзина в сессии и корзина в БД (если такая была) будут объеденины, а кол-во одинаковых товаров просуммируются
            case 'sum':
                $this->mergeBasket_sum( $sessionProducts );
                break;

            // корзина в БД будет полностью заменена новой
            case 'new':
                $this->mergeBasket_new( $sessionProducts );
                break;

            // в БД будут добавлены новые товары, а у совпадающих сохраниться наибольшее кол-во
            case 'max':
                $this->mergeBasket_max( $sessionProducts );
                break;

            // в БД будут добавлены только новые товары
            default:
                $this->mergeBasket_merge( $sessionProducts );
        }

        // Очищаем корзину в сессии
        Yii::$app->session->set($this->owner->storageName, null);
    }

    /**
     * Сливает корзины из сессии и из БД
     * `sum` - корзина в сессии и корзина в БД (если такая была) будут объеденины, а кол-во одинаковых товаров просуммируются
     * @param $sessionProducts
     */
    public function mergeBasket_sum($sessionProducts)
    {
    }

    /**
     * Сливает корзины из сессии и из БД
     * `new` - корзина в БД будет полностью заменена новой
     * @param $sessionProducts
     */
    public function mergeBasket_new($sessionProducts)
    {
    }

    /**
     * Сливает корзины из сессии и из БД
     * `merge` - в БД будут добавлены только новые товары
     * @param $sessionProducts
     */
    public function mergeBasket_merge($sessionProducts)
    {
    }

    /**
     * Сливает корзины из сессии и из БД
     * `max` - в БД будут добавлены новые товары, а у совпадающих сохраниться наибольшее кол-во
     * @param $sessionProducts
     */
    public function mergeBasket_max($sessionProducts)
    {
        $dbProducts = $this->getBasketProducts();

        // Пробегаем все товары из БД и сравниваем кол-во
        // Обработанные товары удаляем из сессии
        foreach($dbProducts as $bItem) {
            if( isset($sessionProducts[ $bItem['hash_product'] ])) {
                if($bItem['count']<$sessionProducts[ $bItem['hash_product'] ]['count']) {
                    /**
                     * @todo Нужно обновить кол-во в БД
                     */
                }

                // Удаляем из сессионной корзины
                unset( $sessionProducts[ $bItem['hash_product'] ] );
            }
        }

        // Пробегаем оставшиеся в сессии товары и добавляем их в БД
        foreach($sessionProducts as $hash => $bItem) {
            $this->insertProduct($hash, $bItem['id_product'], $bItem['price'], Json::decode($bItem['params']), $bItem['count']);
        }
    }
}