<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace PrestaShopBundle\Controller\Admin\Sell\Catalog\Product;

use Exception;
use PrestaShop\PrestaShop\Adapter\Product\Combination\Repository\CombinationRepository;
use PrestaShop\PrestaShop\Adapter\Product\Image\ProductImagePathFactory;
use PrestaShop\PrestaShop\Core\Domain\Product\AttributeGroup\Attribute\QueryResult\Attribute;
use PrestaShop\PrestaShop\Core\Domain\Product\AttributeGroup\Query\GetAttributeGroupList;
use PrestaShop\PrestaShop\Core\Domain\Product\AttributeGroup\Query\GetProductAttributeGroups;
use PrestaShop\PrestaShop\Core\Domain\Product\AttributeGroup\QueryResult\AttributeGroup;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\Command\BulkDeleteCombinationCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\Command\DeleteCombinationCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\Command\GenerateProductCombinationsCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\Exception\CombinationException;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\Exception\CombinationNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\Query\GetEditableCombinationsList;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\QueryResult\CombinationListForEditing;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\ValueObject\CombinationId;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Product\Stock\Exception\ProductStockConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\FormBuilderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Handler\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\ProductCombinationFilters;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CombinationController extends FrameworkBundleAdminController
{
    /**
     * Options used for the number of combinations per page
     */
    private const COMBINATIONS_PAGINATION_OPTIONS = [ProductCombinationFilters::LIST_LIMIT, 20, 50, 100];

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))")
     *
     * @param Request $request
     * @param int $combinationId
     *
     * @return Response
     */
    public function editAction(Request $request, int $combinationId): Response
    {
        $liteDisplaying = $request->query->has('liteDisplaying');
        try {
            $combinationForm = $this->getCombinationFormBuilder()->getFormFor($combinationId);
        } catch (CombinationNotFoundException $e) {
            return $this->render('@PrestaShop/Admin/Exception/not_found.html.twig', [
                'errorMessage' => $this->getErrorMessageForException($e, $this->getErrorMessages($e)),
            ]);
        }

        try {
            $combinationForm->handleRequest($request);

            $result = $this->getCombinationFormHandler()->handleFor($combinationId, $combinationForm);

            if ($result->isSubmitted() && $result->isValid()) {
                $this->addFlash('success', $this->trans('Update successful', 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_products_combinations_edit_combination', [
                    'combinationId' => $combinationId,
                    'liteDisplaying' => $liteDisplaying,
                ]);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/Combination/edit.html.twig', [
            'lightDisplay' => $liteDisplaying,
            'combinationForm' => $combinationForm->createView(),
        ]);
    }

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))")
     *
     * @param int $productId
     *
     * @return Response
     */
    public function bulkEditFormAction(int $productId): Response
    {
        $bulkCombinationForm = $this->getBulkCombinationFormBuilder()->getForm([], ['product_id' => $productId]);

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/Combination/bulk.html.twig', [
            'bulkCombinationForm' => $bulkCombinationForm->createView(),
        ]);
    }

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))")
     *
     * @param Request $request
     * @param int $productId
     * @param int $combinationId
     *
     * @return JsonResponse
     */
    public function bulkEditAction(Request $request, int $productId, int $combinationId): JsonResponse
    {
        try {
            // PATCH request is required to avoid disabled fields to be forced with null values
            $bulkCombinationForm = $this->getBulkCombinationFormBuilder()->getFormFor($combinationId, [], [
                'method' => Request::METHOD_PATCH,
                'product_id' => $productId,
            ]);
        } catch (CombinationNotFoundException $e) {
            return $this->returnErrorJsonResponse(
                ['error' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $bulkCombinationForm->handleRequest($request);
            $result = $this->getBulkCombinationFormHandler()->handleFor($combinationId, $bulkCombinationForm);

            if (!$result->isSubmitted()) {
                return $this->json(['errors' => [
                    'form' => [
                        $this->trans('No submitted data.', 'Admin.Notifications.Error'),
                    ],
                ]], Response::HTTP_BAD_REQUEST);
            }

            if ($result->isValid()) {
                return $this->json([]);
            }
        } catch (CombinationException $e) {
            return $this->returnErrorJsonResponse(
                ['error' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->json(['errors' => $this->getFormErrorsForJS($bulkCombinationForm)], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @AdminSecurity("is_granted('read', 'AdminProducts')")
     *
     * Note: role must be hard coded because there is no route associated to this action therefore not
     * _legacy_controller request parameter.
     *
     * Renders combinations list prototype (which contains form inputs submittable by ajax)
     * It can only be embedded into another view (does not have a route), it is included in this template:
     *
     * src/PrestaShopBundle/Resources/views/Admin/Sell/Catalog/Product/Combination/external_tab.html.twig
     *
     * @param int $productId
     *
     * @return Response
     */
    public function paginatedListAction(int $productId): Response
    {
        $combinationsForm = $this->getCombinationListFormBuilder()->getForm();

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/Combination/paginated_list.html.twig', [
            'productId' => $productId,
            'combinationLimitChoices' => self::COMBINATIONS_PAGINATION_OPTIONS,
            'combinationsLimit' => ProductCombinationFilters::LIST_LIMIT,
            'combinationsForm' => $combinationsForm->createView(),
        ]);
    }

    /**
     * @AdminSecurity("is_granted('read', request.get('_legacy_controller'))")
     *
     * @param int $productId
     *
     * @return JsonResponse
     */
    public function getAttributeGroupsAction(int $productId): JsonResponse
    {
        /** @var AttributeGroup[] $attributeGroups */
        $attributeGroups = $this->getQueryBus()->handle(new GetProductAttributeGroups($productId, true));

        return $this->json($this->formatAttributeGroupsForPresentation($attributeGroups));
    }

    /**
     * @AdminSecurity("is_granted('read', request.get('_legacy_controller'))")
     *
     * @return JsonResponse
     */
    public function getAllAttributeGroupsAction(): JsonResponse
    {
        /** @var AttributeGroup[] $attributeGroups */
        $attributeGroups = $this->getQueryBus()->handle(new GetAttributeGroupList(true));

        return $this->json($this->formatAttributeGroupsForPresentation($attributeGroups));
    }

    /**
     * @AdminSecurity("is_granted('read', request.get('_legacy_controller'))")
     *
     * @param int $productId
     * @param ProductCombinationFilters $combinationFilters
     *
     * @return JsonResponse
     */
    public function getListAction(int $productId, ProductCombinationFilters $combinationFilters): JsonResponse
    {
        $combinationsList = $this->getQueryBus()->handle(new GetEditableCombinationsList(
            $productId,
            $this->getContextLangId(),
            $combinationFilters->getLimit(),
            $combinationFilters->getOffset(),
            $combinationFilters->getOrderBy(),
            $combinationFilters->getOrderWay(),
            $combinationFilters->getFilters()
        ));

        return $this->json($this->formatListForPresentation($combinationsList));
    }

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))")
     *
     * @param int $productId
     * @param ProductCombinationFilters $filters
     *
     * @return JsonResponse
     */
    public function getCombinationIdsAction(int $productId, ProductCombinationFilters $filters): JsonResponse
    {
        /** @var CombinationRepository $repository */
        $repository = $this->get('prestashop.adapter.product.combination.repository.combination_repository');

        $combinationIds = $repository->getCombinationIds(new ProductId($productId), $filters);
        $data = [];
        foreach ($combinationIds as $combinationId) {
            $data[] = $combinationId->getValue();
        }

        return $this->json($data);
    }

    /**
     * @AdminSecurity("is_granted('delete', request.get('_legacy_controller'))")
     *
     * @param int $combinationId
     *
     * @return JsonResponse
     */
    public function deleteAction(int $combinationId): JsonResponse
    {
        try {
            $this->getCommandBus()->handle(new DeleteCombinationCommand($combinationId));
        } catch (Exception $e) {
            return $this->json([
                'error' => $this->getErrorMessageForException($e, $this->getErrorMessages($e)),
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'message' => $this->trans('Successful deletion', 'Admin.Notifications.Success'),
        ]);
    }

    /**
     * @todo: this has left unused after some changes, but it may be needed for bulk deletion by chunks
     *        (remove this code if its still unused after issue #28491 is closed)
     *
     * @AdminSecurity("is_granted('read', request.get('_legacy_controller'))")
     *
     * @param int $productId
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function bulkDeleteAction(int $productId, Request $request): JsonResponse
    {
        $combinationIds = $request->request->get('combinationIds');
        if (!$combinationIds) {
            return $this->json([
                'error' => $this->getFallbackErrorMessage('', 0, 'Missing combinationIds in request body'),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->getCommandBus()->handle(new BulkDeleteCombinationCommand($productId, json_decode($combinationIds)));
        } catch (Exception $e) {
            return $this->json([
                'error' => $this->getErrorMessageForException($e, $this->getErrorMessages($e)),
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'message' => $this->trans('Successful deletion', 'Admin.Notifications.Success'),
        ]);
    }

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))")
     *
     * @param int $productId
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateCombinationFromListingAction(int $productId, Request $request): JsonResponse
    {
        $combinationsListForm = $this->getCombinationListFormBuilder()->getForm([], [
            'method' => Request::METHOD_PATCH,
        ]);

        try {
            $combinationsListForm->handleRequest($request);
            $result = $this->getCombinationListFormHandler()->handleFor($productId, $combinationsListForm);

            if (!$result->isSubmitted()) {
                return $this->json(['errors' => $this->getFormErrorsForJS($combinationsListForm)], Response::HTTP_BAD_REQUEST);
            } elseif (!$result->isValid()) {
                return $this->json([
                    'errors' => $this->getFormErrorsForJS($combinationsListForm),
                    'formContent' => $this->renderView('@PrestaShop/Admin/Sell/Catalog/Product/Combination/combination_list_form.html.twig', [
                        'combinationsForm' => $combinationsListForm->createView(),
                    ]),
                ], Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return $this->json(
                ['errors' => [$this->getFallbackErrorMessage(get_class($e), $e->getCode(), $e->getMessage())]],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->json([
            'message' => $this->trans('Update successful', 'Admin.Notifications.Success'),
        ]);
    }

    /**
     * @AdminSecurity(
     *     "is_granted('create', request.get('_legacy_controller')) && is_granted('update', request.get('_legacy_controller'))"
     * )
     *
     * @param int $productId
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function generateCombinationsAction(int $productId, Request $request): JsonResponse
    {
        $requestAttributeGroups = $request->request->get('attributes');
        $attributes = [];
        foreach ($requestAttributeGroups as $attributeGroupId => $requestAttributes) {
            $attributes[(int) $attributeGroupId] = array_map('intval', $requestAttributes);
        }

        try {
            /** @var CombinationId[] $combinationsIds */
            $combinationsIds = $this->getCommandBus()->handle(new GenerateProductCombinationsCommand($productId, $attributes));
        } catch (Exception $e) {
            return $this->json([
                'error' => [
                    $this->getErrorMessageForException($e, $this->getErrorMessages($e)),
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'combination_ids' => array_map(function (CombinationId $combinationId) { return $combinationId->getValue(); }, $combinationsIds),
        ]);
    }

    /**
     * @param AttributeGroup[] $attributeGroups
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatAttributeGroupsForPresentation(array $attributeGroups): array
    {
        $contextLangId = $this->getContextLangId();

        $formattedGroups = [];
        foreach ($attributeGroups as $attributeGroup) {
            $attributes = [];
            /** @var Attribute $attribute */
            foreach ($attributeGroup->getAttributes() as $attribute) {
                $attributeNames = $attribute->getLocalizedNames();
                $attributeData = [
                    'id' => $attribute->getAttributeId(),
                    'name' => $attributeNames[$contextLangId] ?? reset($attributeNames),
                ];
                if (null !== $attribute->getColor()) {
                    $attributeData['color'] = $attribute->getColor();
                }
                $attributes[] = $attributeData;
            }

            $publicNames = $attributeGroup->getLocalizedPublicNames();
            $names = $attributeGroup->getLocalizedNames();
            $formattedGroups[] = [
                'id' => $attributeGroup->getAttributeGroupId(),
                'name' => $names[$contextLangId] ?? reset($names),
                'publicName' => $publicNames[$contextLangId] ?? reset($publicNames),
                'attributes' => $attributes,
            ];
        }

        return $formattedGroups;
    }

    /**
     * @param CombinationListForEditing $combinationListForEditing
     *
     * @return array<string, array<int, array<string,bool|int|string>>|int>
     */
    private function formatListForPresentation(CombinationListForEditing $combinationListForEditing): array
    {
        $data = [
            'combinations' => [],
            'total' => $combinationListForEditing->getTotalCombinationsCount(),
        ];

        $fallbackImageUrl = $this->getFallbackImageUrl();
        foreach ($combinationListForEditing->getCombinations() as $combination) {
            $data['combinations'][] = [
                'combination_id' => $combination->getCombinationId(),
                'is_selected' => false,
                'name' => $combination->getCombinationName(),
                'reference' => $combination->getReference(),
                'impact_on_price_te' => (string) $combination->getImpactOnPrice(),
                'quantity' => $combination->getQuantity(),
                'is_default' => $combination->isDefault(),
                'image_url' => $combination->getImageUrl() ?: $fallbackImageUrl,
            ];
        }

        return $data;
    }

    /**
     * @return string
     */
    private function getFallbackImageUrl(): string
    {
        $imageUrlFactory = $this->get('prestashop.adapter.product.image.product_image_url_factory');

        return $imageUrlFactory->getNoImagePath(ProductImagePathFactory::IMAGE_TYPE_SMALL_DEFAULT);
    }

    /**
     * @return FormHandlerInterface
     */
    private function getCombinationListFormHandler(): FormHandlerInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.combination_list_form_handler');
    }

    /**
     * @return FormHandlerInterface
     */
    private function getCombinationFormHandler(): FormHandlerInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.combination_form_handler');
    }

    /**
     * @return FormBuilderInterface
     */
    private function getCombinationFormBuilder(): FormBuilderInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.builder.combination_form_builder');
    }

    /**
     * @return FormHandlerInterface
     */
    private function getBulkCombinationFormHandler(): FormHandlerInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.bulk_combination_form_handler');
    }

    /**
     * @return FormBuilderInterface
     */
    private function getBulkCombinationFormBuilder(): FormBuilderInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.builder.bulk_combination_form_builder');
    }

    /**
     * @return FormBuilderInterface
     */
    private function getCombinationListFormBuilder(): FormBuilderInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.builder.combination_list_form_builder');
    }

    /**
     * Gets an error by exception class and its code.
     *
     * @param Exception $e
     *
     * @return array
     */
    private function getErrorMessages(Exception $e): array
    {
        return [
            ProductConstraintException::class => [
                ProductConstraintException::INVALID_LOW_STOCK_THRESHOLD => $this->trans(
                    'The %s field is invalid.',
                    'Admin.Notifications.Error',
                    [sprintf('"%s"', $this->trans('Low stock level', 'Admin.Catalog.Feature'))]
                ),
                ProductConstraintException::INVALID_LOW_STOCK_ALERT => $this->trans(
                    'The %s field is invalid.',
                    'Admin.Notifications.Error',
                    [sprintf('"%s"', $this->trans('Low stock alert', 'Admin.Catalog.Feature'))]
                ),
                ProductConstraintException::INVALID_AVAILABLE_DATE => $this->trans(
                    'The %s field is invalid.',
                    'Admin.Notifications.Error',
                    [sprintf('"%s"', $this->trans('Availability date', 'Admin.Catalog.Feature'))]
                ),
                ProductConstraintException::INVALID_MINIMAL_QUANTITY => $this->trans(
                    'The %s field is invalid.',
                    'Admin.Notifications.Error',
                    [sprintf('"%s"', $this->trans('Minimum order quantity', 'Admin.Catalog.Feature'))]
                ),
            ],
            ProductStockConstraintException::class => [
                ProductStockConstraintException::INVALID_QUANTITY => $this->trans(
                    'The %s field is invalid.',
                    'Admin.Notifications.Error',
                    [sprintf('"%s"', $this->trans('Quantity', 'Admin.Catalog.Feature'))]
                ),
                ProductStockConstraintException::INVALID_LOCATION => $this->trans(
                    'The %s field is invalid.',
                    'Admin.Notifications.Error',
                    [sprintf('"%s"', $this->trans('Stock location', 'Admin.Catalog.Feature'))]
                ),
            ],
            CombinationNotFoundException::class => $this->trans(
                'The object cannot be loaded (or found)',
                'Admin.Notifications.Error'
            ),
        ];
    }
}
