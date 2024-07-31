# OVERVIEW
`knn_cluster.py` does a KNN clustering of data and outputs it to stdout. Supports pluggable custom distance measures and additional same-cluster rules.

# USAGE
See options:

    python ./knn_cluster.py --help

Run with default config:

    python ./knn_cluster.py

# CONFIGURATION
Configuration is via a python config file (see example in [./config.py](./config.py). Certain config vars can be overridden from the command line.

# VISUALIZATION
`plot.py` is a basic script to vizualize output of `knn_cluster.py`.

# TODO
[TODO](./TODO.md)
