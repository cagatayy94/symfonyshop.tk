<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\Web\Product as ProductService;
use App\Service\Web\SiteSettings as SiteSettings;

class ProductController extends AbstractController
{
    /**
     * @Route("/product-detail/{slug}/{id}", name="product_detail" )
     */
    public function productDetailAction(ProductService $productService, SiteSettings $siteSettings, Request $request)
    {
        $id = $request->attributes->get('id');
        $productDetail = $productService->getDetail($id);
        $banner = $siteSettings->getBanner();

        if (!$productDetail) {
            return $this->redirect($this->generateUrl('index'));
        }

        $user = $this->getUser();

        return $this->render('Web/Product/product_detail.html.php', [
            'productDetail' => $productDetail,
            'banner' => $banner,
            'id' => $id,
            'user' => $user,
        ]);
    }

    /**
     * @Route("/add/favorite", name="add_favorite")
     */
    public function addFavoriteAction(Request $request, ProductService $productService)
    {
        $productId = $request->request->get('productId');
        $user = $this->getUser();
        try {
            $productService->addFavorite($productId, $user->getId());

            return new JsonResponse([
                'success' => true,
            ]);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => [
                    'message' => $exception->getMessage()
                ]
            ]);
        }
    }
}
