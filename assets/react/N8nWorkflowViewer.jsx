import React, { useEffect, useState, useCallback } from 'react';
import ReactDOM from 'react-dom/client';
import {
    ReactFlow,
    Background,
    Controls,
    MiniMap,
    useNodesState,
    useEdgesState,
    Panel,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import './N8nWorkflowViewer.css';

// Dark theme styles for ReactFlow
const rfStyle = {
    backgroundColor: '#0a0a0a',
};

const nodeStyle = {
    background: '#1a1a1a',
    color: '#ffffff',
    border: '1px solid #2a2a2a',
    borderRadius: '8px',
    padding: '10px',
    fontSize: '12px',
};

const edgeStyle = {
    stroke: '#ff6b35',
    strokeWidth: 2,
};

// Custom node component for N8N workflow nodes
const CustomNode = ({ data }) => {
    return (
        <div style={nodeStyle}>
            <div style={{ fontWeight: 'bold', marginBottom: '4px' }}>
                {data.label}
            </div>
            {data.type && (
                <div style={{ fontSize: '10px', color: '#9ca3af' }}>
                    {data.type}
                </div>
            )}
        </div>
    );
};

const nodeTypes = {
    default: CustomNode,
};

const N8nWorkflowViewer = ({ orgId }) => {
    const [nodes, setNodes, onNodesChange] = useNodesState([]);
    const [edges, setEdges, onEdgesChange] = useEdgesState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    // Fetch workflow data from backend
    const fetchWorkflowData = useCallback(async () => {
        try {
            setLoading(true);
            setError(null);

            const response = await fetch(`/instructions/api/n8n-workflow/${orgId}`);
            const data = await response.json();

            if (data.error) {
                setError(data.error);
            } else {
                // Apply custom styling to nodes
                const styledNodes = data.nodes.map(node => ({
                    ...node,
                    style: nodeStyle,
                }));

                // Apply custom styling to edges
                const styledEdges = data.edges.map(edge => ({
                    ...edge,
                    style: edgeStyle,
                    animated: true,
                }));

                setNodes(styledNodes);
                setEdges(styledEdges);
            }
        } catch (err) {
            setError('Failed to load workflow');
            console.error('Error fetching workflow:', err);
        } finally {
            setLoading(false);
        }
    }, [orgId, setNodes, setEdges]);

    useEffect(() => {
        fetchWorkflowData();
    }, [fetchWorkflowData]);

    if (loading) {
        return (
            <div style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                height: '100%',
                color: '#6b7280',
            }}>
                Loading workflow...
            </div>
        );
    }

    if (error) {
        return (
            <div style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                height: '100%',
                color: '#ef4444',
                padding: '20px',
                textAlign: 'center',
            }}>
                <div style={{ marginBottom: '10px' }}>Failed to load workflow</div>
                <div style={{ fontSize: '12px', color: '#9ca3af' }}>{error}</div>
            </div>
        );
    }

    if (nodes.length === 0) {
        return (
            <div style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                height: '100%',
                color: '#6b7280',
            }}>
                No workflow nodes to display
            </div>
        );
    }

    return (
        <ReactFlow
            nodes={nodes}
            edges={edges}
            onNodesChange={onNodesChange}
            onEdgesChange={onEdgesChange}
            nodeTypes={nodeTypes}
            style={rfStyle}
            fitView
            attributionPosition="bottom-left"
            nodesDraggable={false}
            nodesConnectable={false}
            elementsSelectable={false}
            panOnDrag={true}
            zoomOnScroll={true}
            preventScrolling={false}
        >
            <Background color="#2a2a2a" gap={16} size={1} />
            <Controls />
            <MiniMap
                nodeColor="#1a1a1a"
                nodeStrokeColor="#ff6b35"
                nodeStrokeWidth={2}
                style={{
                    backgroundColor: '#0a0a0a',
                    border: '1px solid #2a2a2a',
                }}
            />
            <Panel position="top-left">
                <div style={{
                    padding: '10px',
                    backgroundColor: '#1a1a1a',
                    border: '1px solid #2a2a2a',
                    borderRadius: '6px',
                    color: '#ffffff',
                    fontSize: '12px',
                }}>
                    Workflow (Read-Only)
                </div>
            </Panel>
        </ReactFlow>
    );
};

// Function to initialize the component on a specific DOM element
window.initN8nWorkflowViewer = (containerId, orgId) => {
    const container = document.getElementById(containerId);
    if (container) {
        const root = ReactDOM.createRoot(container);
        root.render(
            <React.StrictMode>
                <N8nWorkflowViewer orgId={orgId} />
            </React.StrictMode>
        );
        return root;
    }
    return null;
};

export default N8nWorkflowViewer;
