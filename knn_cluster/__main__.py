import sys
from knn_cluster import cluster, plot

if len(sys.argv) <= 1:
  print('Usage: python -m knn_cluster <command>')
  sys.exit(1)
elif sys.argv[1] == 'cluster':
  cluster.main(sys.argv[2:])
elif sys.argv[1] == 'plot':
  plot.main(sys.argv[2:])
else:
  print(f'ERROR: Unknown command {sys.argv[1]}')

