<?php

namespace Pumukit\OpencastBundle\Services;

class WorkflowService
{
    private $clientService;
    private $deletionWorkflowName;
    private $deleteArchiveMediaPackage;

    public function __construct(ClientService $clientService, bool $deleteArchiveMediaPackage = false, string $deletionWorkflowName = 'delete-archive')
    {
        $this->clientService = $clientService;
        $this->deleteArchiveMediaPackage = $deleteArchiveMediaPackage;
        $this->deletionWorkflowName = $deletionWorkflowName;
    }

    /**
     * Check workflow ended.
     */
    public function stopSucceededWorkflows(string $mediaPackageId = ''): bool
    {
        $errors = 0;
        if (!$this->deleteArchiveMediaPackage) {
            return true;
        }

        if ($mediaPackageId) {
            $deletionWorkflow = $this->getAllWorkflowInstances($mediaPackageId, $this->deletionWorkflowName);
            if (null !== $deletionWorkflow) {
                $errors = $this->stopSucceededWorkflow($deletionWorkflow, $errors);
                $workflows = $this->getAllWorkflowInstances($mediaPackageId);
                foreach ($workflows as $workflow) {
                    $errors = $this->stopSucceededWorkflow($workflow, $errors);
                }
            }
        } else {
            $deletionWorkflows = $this->getAllWorkflowInstances('', $this->deletionWorkflowName);
            foreach ($deletionWorkflows as $deletionWorkflow) {
                $errors = $this->stopSucceededWorkflow($deletionWorkflow, $errors);
                $mediaPackageId = $this->getMediaPackageIdFromWorkflow($deletionWorkflow);
                $mediaPackageWorkflows = $this->getAllWorkflowInstances($mediaPackageId, '');
                foreach ($mediaPackageWorkflows as $mediaPackageWorkflow) {
                    $errors = $this->stopSucceededWorkflow($mediaPackageWorkflow, $errors);
                }
            }
        }

        return !($errors > 0);
    }

    /**
     * Get all workflow instances with given mediapackage id.
     */
    private function getAllWorkflowInstances(string $id = '', string $workflowName = '')
    {
        $statistics = $this->clientService->getWorkflowStatistics();

        $total = $statistics['statistics']['total'] ?? 0;

        if (0 === $total) {
            return null;
        }

        $decode = $this->clientService->getCountedWorkflowInstances($id, $total, $workflowName);

        $instances = $decode['workflows']['workflow'] ?? [];
        if (isset($instances['state'])) {
            $instances = ['0' => $instances];
        }

        return $instances;
    }

    private function isWorkflowSucceeded(array $workflow = []): bool
    {
        return $workflow && isset($workflow['state']) && 'SUCCEEDED' === $workflow['state'];
    }

    private function getMediaPackageIdFromWorkflow(array $workflow = [])
    {
        if ($workflow && isset($workflow['mediapackage']['id'])) {
            return $workflow['mediapackage']['id'];
        }

        return null;
    }

    /**
     * Delete workflow if succeeded and get the errors.
     */
    private function stopSucceededWorkflow(array $workflow = [], int $errors = 0): int
    {
        if (!$this->deleteArchiveMediaPackage) {
            return $errors;
        }

        $isSucceeded = $this->isWorkflowSucceeded($workflow);
        if ($isSucceeded) {
            $output = $this->clientService->stopWorkflow($workflow);
            if (!$output) {
                ++$errors;
            }
        }

        return $errors;
    }
}
