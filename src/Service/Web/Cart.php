<?php
namespace App\Service\Web;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

use App\Entity\Admin\AdminAccount;
use App\Sdk\ServiceTrait;
use App\Sdk\AdminServiceTrait;

class Cart
{
    use ServiceTrait;

    public function addCart($productId, $variantId, $userId)
    {
        $logDetails = $this->getArguments(__FUNCTION__, func_get_args());

        $logFullDetails = [
            'entity' => 'Cart',
            'activity' => 'addCart',
            'activityId' => 0,
            'details' => $logDetails
        ];

        $connection = $this->connection;

        $productId = (int) $productId;
        $userId = (int) $userId;
        $variantId = (int) $variantId;

        try {
            if (!$productId) {
                throw new \InvalidArgumentException('Ürün bulunamadı');
            }

            if (!$userId) {
                throw new \InvalidArgumentException('Kullanıcı bulunamadı');
            }

            if (!$variantId) {
                throw new \InvalidArgumentException('Variant seçiniz');
            }

            $sql = "
                SELECT 
                    id
                FROM
                    cart
                WHERE
                    product_id = :product_id
                AND
                    user_account_id = :user_account_id
                AND
                    variant_id = :variant_id";

            $statement = $connection->prepare($sql);

            $statement->bindValue('product_id', $productId);
            $statement->bindValue('user_account_id', $userId);
            $statement->bindValue('variant_id', $variantId);

            $statement->execute();

            $itemExist = $statement->fetch();

            if ($itemExist) {
                $sql = "
                    UPDATE 
                        cart
                    SET
                        quantity = quantity + 1
                    WHERE
                        product_id = :product_id
                    AND
                        user_account_id = :user_account_id
                    AND
                        variant_id = :variant_id";

                $statement = $connection->prepare($sql);

                $statement->bindValue('product_id', $productId);
                $statement->bindValue('user_account_id', $userId);
                $statement->bindValue('variant_id', $variantId);

                $statement->execute();
                

            }else{
                $sql = "
                    INSERT INTO cart
                        (product_id, user_account_id, variant_id)
                    VALUES
                        (:product_id, :user_account_id, :variant_id)
                    RETURNING id";

                $statement = $connection->prepare($sql);

                $statement->bindValue('product_id', $productId);
                $statement->bindValue('user_account_id', $userId);
                $statement->bindValue('variant_id', $variantId);

                $statement->execute();

                $cartNo = $statement->fetchColumn();

                $sql = "
                    UPDATE 
                        cart
                    SET
                        cart_no = :cart_no
                    WHERE
                        user_account_id = :user_account_id";

                $statement = $connection->prepare($sql);

                $statement->bindValue('cart_no', $cartNo);
                $statement->bindValue('user_account_id', $userId);

                $statement->execute();
            }

            $this->logger->info('Added cart', $logFullDetails);
        } catch (\InvalidArgumentException $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not added cart', $logFullDetails);
            throw $exception;
        } catch (\Exception $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not added cart', $logFullDetails);
            throw new \Exception("Bir sorun oluştu");            
        }
    }

    public function getCart($productId)
    {
        $connection = $this->connection;

        $sql = "
            SELECT
                c.id,
                c.quantity quantity,

                p.id product_id,
                p.name product_name,
                p.variant_title variant_title,
                p.price product_price,
                p.cargo_price cargo_price,

                pv.name variant_name,

                p.price*c.quantity::float(2) as total,

                cc.name cargo_company_name,

                (
                    SELECT
                        ROW_TO_JSON(a) as billing_address
                    FROM
                         (
                             SELECT
                                address_name,
                                full_name,
                                address,
                                county,
                                city,
                                mobile
                             FROM
                                address
                             WHERE
                                id = c.billing_address_id
                         ) a
                ) billing_address,

                (
                    SELECT
                        ROW_TO_JSON(a) as shipping_address
                    FROM
                         (
                             SELECT
                                address_name,
                                full_name,
                                address,
                                county,
                                city,
                                mobile
                             FROM
                                address
                             WHERE
                                id = c.shipping_address_id
                         ) a
                ) shipping_address,

                (
                    SELECT
                        path
                    FROM
                        product_photo
                    WHERE
                        product_id = c.product_id
                    LIMIT 1
                ) path
            FROM
                cart c
            LEFT JOIN
                product p ON p.id = c.product_id
            LEFT JOIN
                product_variant pv on c.variant_id = pv.id
            LEFT JOIN
                cargo_company cc on c.cargo_company_id = cc.id
            WHERE
                user_account_id = :user_account_id
            GROUP BY
                c.id, p.id, p.name, c.quantity, p.cargo_price, p.variant_title, p.price, pv.name, cc.name";

        $statement = $connection->prepare($sql);
        $statement->bindValue('user_account_id', $productId);

        $statement->execute();

        return $statement->fetchAll();
    }

    public function getCartTotalAndQuantity($productId)
    {
        $connection = $this->connection;

        $sql = "
            WITH
                 Cart AS
                     (
                        SELECT
                            c.id,
                            c.quantity quantity,

                            p.name product_name,
                            p.variant_title variant_title,
                            p.price product_price,

                            pv.name variant_name,

                            p.price*c.quantity::float(2) as total,

                            (
                                SELECT
                                    path
                                FROM
                                    product_photo
                                WHERE
                                    product_id = c.product_id
                                LIMIT 1
                            ) path
                        FROM
                            cart c
                        LEFT JOIN
                            product p ON p.id = c.product_id
                        LEFT JOIN
                            product_variant pv on c.variant_id = pv.id
                        WHERE
                            user_account_id = :user_account_id
                        GROUP BY
                            c.id, p.name, c.quantity, p.variant_title, p.price, pv.name
                    )
            SELECT
                sum(total)::float(2),
                count(id)::int
            FROM
                Cart";

        $statement = $connection->prepare($sql);
        $statement->bindValue('user_account_id', $productId);

        $statement->execute();

        return $statement->fetchAll();
    }

    public function remove($userId, $cartId)
    {
        $logDetails = $this->getArguments(__FUNCTION__, func_get_args());

        $logFullDetails = [
            'entity' => 'Cart',
            'activity' => 'remove',
            'activityId' => 0,
            'details' => $logDetails
        ];

        $connection = $this->connection;

        $userId = (int) $userId;
        $cartId = (int) $cartId;

        try {
            if (!$userId) {
                throw new \InvalidArgumentException('Kullanıcı bulunamadı');
            }

            if (!$cartId) {
                throw new \InvalidArgumentException('Sepet bulunamadı');
            }

            $sql = "
                DELETE FROM 
                    Cart
                WHERE 
                    id = :id 
                AND
                    user_account_id = :user_account_id";

            $statement = $connection->prepare($sql);

            $statement->bindValue('id', $cartId);
            $statement->bindValue('user_account_id', $userId);

            $statement->execute();

            $this->logger->info('Removed from cart', $logFullDetails);
        } catch (\InvalidArgumentException $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not removed from cart', $logFullDetails);
            throw $exception;
        } catch (\Exception $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not removed from cart', $logFullDetails);
            throw new \Exception("Bir sorun oluştu");            
        }
    }

    public function updateQuantity($userId, $cartId, $type)
    {
        $logDetails = $this->getArguments(__FUNCTION__, func_get_args());

        $logFullDetails = [
            'entity' => 'Cart',
            'activity' => 'updateQuantity',
            'activityId' => 0,
            'details' => $logDetails
        ];

        $types = ['minus', 'plus'];

        $connection = $this->connection;

        $userId = (int) $userId;
        $cartId = (int) $cartId;

        try {
            if (!$userId) {
                throw new \InvalidArgumentException('Kullanıcı bulunamadı');
            }

            if (!$cartId) {
                throw new \InvalidArgumentException('Sepet bulunamadı');
            }

            if (!$type || $type == "" || !in_array($type, $types)) {
                throw new \InvalidArgumentException('Aksiyon tipi bulunamadı');
            }

            if ($type == 'plus') {
                //get quantity and stock
                $sql = "
                    SELECT
                        c.quantity,
                        pv.stock
                    FROM
                        cart c
                    LEFT JOIN
                        product_variant pv on c.variant_id = pv.id
                    WHERE
                        c.id = :id
                    AND
                        c.user_account_id = :user_account_id";

                $statement = $connection->prepare($sql);

                $statement->bindValue('id', $cartId);
                $statement->bindValue('user_account_id', $userId);
                $statement->execute();

                $quantityAndStock = $statement->fetch();

                if ($quantityAndStock['quantity']+1 > $quantityAndStock['stock']) {
                    throw new \InvalidArgumentException('Yeteri kadar stok bulunmuyor adet artırılamadı.');
                }

                $sql = "
                    UPDATE 
                        Cart
                    SET 
                        quantity = quantity + 1
                    WHERE 
                        id = :id 
                    AND
                        user_account_id = :user_account_id";

                $statement = $connection->prepare($sql);

                $statement->bindValue('id', $cartId);
                $statement->bindValue('user_account_id', $userId);

                $statement->execute();

                return;
            }

            if ($type == 'minus') {
                //get quantity
                $sql = "
                    SELECT
                        quantity
                    FROM
                        cart
                    WHERE
                        id = :id
                    AND
                        user_account_id = :user_account_id";

                $statement = $connection->prepare($sql);

                $statement->bindValue('id', $cartId);
                $statement->bindValue('user_account_id', $userId);
                $statement->execute();

                $quantity = $statement->fetchColumn();

                if ($quantity > 1) {
                    $sql = "
                        UPDATE 
                            Cart
                        SET 
                            quantity = quantity - 1
                        WHERE 
                            id = :id 
                        AND
                            user_account_id = :user_account_id";

                    $statement = $connection->prepare($sql);

                    $statement->bindValue('id', $cartId);
                    $statement->bindValue('user_account_id', $userId);

                    $statement->execute();
                }else{
                    $this->remove($userId, $cartId);
                }
                
                return;
            }

            $this->logger->info('Updated quantity cart element', $logFullDetails);
        } catch (\InvalidArgumentException $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not updated quantity cart element', $logFullDetails);
            throw $exception;
        } catch (\Exception $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not updated quantity cart element', $logFullDetails);
            throw new \Exception($exception->getMessage());            
        }
    }

    public function getCargoCompanyForCart()
    {
        return $this->connection->executeQuery('
            SELECT
                id,
                name
            FROM
                cargo_company
                '
            )->fetchAll();
    }

    public function cartUpdateAddressAndCargo($user, $billingAddressId, $shippingAddressId, $cargoCompanyId)
    {
        $logDetails = $this->getArguments(__FUNCTION__, func_get_args());

        $logFullDetails = [
            'entity' => 'Cart',
            'activity' => 'cartUpdateAddressAndCargo',
            'activityId' => 0,
            'details' => $logDetails
        ];

        $connection = $this->connection;

        $billingAddressId = (int) $billingAddressId;
        $shippingAddressId = (int) $shippingAddressId;
        $cargoCompanyId = (int) $cargoCompanyId;

        try {
            if (!$user) {
                throw new \InvalidArgumentException('Kullanıcı bulunamadı');
            }

            if (!$cargoCompanyId) {
                throw new \InvalidArgumentException('Kargo firması bulunamadı');
            }

            if (!$billingAddressId && $this->isThisAddressBelongsTheCurrentUser($user, $billingAddressId)) {
                throw new \InvalidArgumentException('Fatura adresi bulunamadı');
            }

            if (!$shippingAddressId && $this->isThisAddressBelongsTheCurrentUser($user, $shippingAddressId)) {
                throw new \InvalidArgumentException('Kargo Adresi bulunamadı');
            }

            $connection->executeQuery('
                UPDATE
                    cart
                SET
                    cargo_company_id = :cargo_company_id,
                    shipping_address_id = :shipping_address_id,
                    billing_address_id = :billing_address_id
                WHERE
                    user_account_id = :user_account_id
                    ', [
                        'cargo_company_id' => $cargoCompanyId,
                        'shipping_address_id' => $shippingAddressId,
                        'billing_address_id' => $billingAddressId,
                        'user_account_id' => $user->getId(),
                    ]
                );


            $this->logger->info('Updated cargo and billing details', $logFullDetails);
        } catch (\InvalidArgumentException $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not updated cargo and billing details', $logFullDetails);
            throw $exception;
        } catch (\Exception $exception) {
            $logFullDetails['details']['exception'] = $exception->getMessage();
            $this->logger->error('Could not updated cargo and billing details', $logFullDetails);
            throw new \Exception($exception->getMessage());            
        }
    }

    public function isThisAddressBelongsTheCurrentUser($user, $addressId)
    {
        return $this->connection->executeQuery('
            SELECT
                count(a.id)
            FROM
                address a
            LEFT JOIN
                user_account_address uaa ON a.id = uaa.address_id
            WHERE
                uaa.user_account_id = :user_account_id
            AND
                a.id = :address_id
                ', [
                    'user_account_id' => $user->getId(),
                    'address_id' => $addressId
                ]
            )->fetchColumn();
    }
}
